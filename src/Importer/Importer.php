<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\Importer;

use Contao\Database;
use Contao\Message;
use Contao\Model;
use Contao\StringUtil;
use HeimrichHannot\EntityImportBundle\Event\AfterImportEvent;
use HeimrichHannot\EntityImportBundle\Event\BeforeImportEvent;
use HeimrichHannot\EntityImportBundle\Model\EntityImportConfigModel;
use HeimrichHannot\EntityImportBundle\Source\SourceInterface;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Importer implements ImporterInterface
{
    /**
     * @var SourceInterface
     */
    protected $source;

    /**
     * @var EntityImportConfigModel
     */
    protected $configModel;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var DatabaseUtil
     */
    private $databaseUtil;

    /**
     * @var ModelUtil
     */
    private $modelUtil;

    /**
     * Importer constructor.
     */
    public function __construct(Model $configModel, SourceInterface $source, EventDispatcherInterface $eventDispatcher, DatabaseUtil $databaseUtil, ModelUtil $modelUtil)
    {
        $this->configModel = $configModel;
        $this->source = $source;
        $this->databaseUtil = $databaseUtil;
        $this->eventDispatcher = $eventDispatcher;
        $this->modelUtil = $modelUtil;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): bool
    {
        $items = $this->getDataFromSource();

        $event = $this->eventDispatcher->dispatch(BeforeImportEvent::NAME, new BeforeImportEvent($items, $this->configModel, $this->source));

        $this->executeImport($event->getItems());

        $this->eventDispatcher->dispatch(AfterImportEvent::NAME, new AfterImportEvent($items, $this->configModel, $this->source));

        return true;
    }

    public function getDataFromSource(): array
    {
        return $this->source->getMappedData();
    }

    public function setDryRun(bool $dry)
    {
        $this->dryRun = $dry;
    }

    public function applyFieldMappingToSourceItem(array $item): array
    {
        $fields = StringUtil::deserialize($this->configModel->fieldMapping);

        $mapped = [];

        foreach ($fields as $field) {
            if ('source_value' === $field['valueType']) {
                $mapped[$field['columnName']] = $item[$field['mappingValue']];
            } elseif ('static_value' === $field['valueType']) {
                $mapped[$field['columnName']] = $field['staticValue'];
            }
        }

        return $mapped;
    }

    protected function executeImport(array $items)
    {
        $database = Database::getInstance();
        $table = $this->configModel->targetTable;

        if (!$database->tableExists($table)) {
            new Exception($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['tableDoesNotExist']);
        }

        try {
            $count = 0;
            $targetTableColumns = $database->getFieldNames($table);

            if ($this->configModel->purgeBeforeImport) {
                $this->databaseUtil->delete($table, $this->configModel->purgeWhereClause);
            }

            $mode = $this->configModel->importMode;

            foreach ($items as $item) {
                $item = $this->applyFieldMappingToSourceItem($item);

                $columnsNotExisting = array_diff(array_keys($item), $targetTableColumns);

                if (!empty($columnsNotExisting)) {
                    throw new Exception($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['tableFieldsDiffer']);
                }

                ++$count;

                if ($this->dryRun) {
                    continue;
                }

                if ('insert' === $mode) {
                    $this->databaseUtil->insert($table, $item);
                } elseif ('merge' === $mode) {
                    $mergeIdentifiers = StringUtil::deserialize($this->configModel->mergeIdentifierFields, true);

                    if (empty($mergeIdentifiers)) {
                        throw new Exception($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['noIdentifierFields']);
                    }

                    $columns = [];
                    $values = [];

                    foreach ($mergeIdentifiers as $mergeIdentifier) {
                        $columns[] = '('.$table.'.'.$mergeIdentifier['target'].'=?)';
                        $values[] = $item[$mergeIdentifier['source']];
                    }

                    $existing = $this->databaseUtil->select($table, '*', implode(' AND ', $columns), $values);

                    if ($existing->numRows > 0) {
                        $this->databaseUtil->update($table, $item, implode(' AND ', $columns), $values);
                    } else {
                        $this->databaseUtil->insert($table, $item);
                    }
                } else {
                    throw new Exception($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['modeNotSet']);
                }
            }

            if ($count > 0) {
                Message::addConfirmation(sprintf($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['successfulImport'], $count));
            } else {
                Message::addInfo(sprintf($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['emptyFile']));
            }
        } catch (\Exception $e) {
            Message::addError(sprintf($GLOBALS['TL_LANG']['tl_entity_import_config']['error']['errorImport'], $count, $e->getMessage()));
        }
    }
}
