<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\EntityImportBundle\DataContainer;

use Contao\System;
use HeimrichHannot\EntityImportBundle\Source\AbstractFileSource;
use HeimrichHannot\EntityImportBundle\Source\CSVFileSource;
use HeimrichHannot\EntityImportBundle\Source\SourceFactory;
use HeimrichHannot\UtilsBundle\File\FileUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;

class EntityImportSourceContainer
{
    const TYPE_DATABASE = 'db';
    const TYPE_FILE = 'file';

    const RETRIEVAL_TYPE_HTTP = 'http';
    const RETRIEVAL_TYPE_CONTAO_FILE_SYSTEM = 'contao_file_system';
    const RETRIEVAL_TYPE_ABSOLUTE_PATH = 'absolute_path';

    const FILETYPE_CSV = 'csv';
    const FILETYPE_JSON = 'json';

    protected $activeBundles;
    protected $database;
    protected $cache;
    /**
     * @var FileUtil
     */
    private $fileUtil;

    /**
     * @var ModelUtil
     */
    private $modelUtil;
    /**
     * @var SourceFactory
     */
    private $sourceFactory;

    public function __construct(FileUtil $fileUtil, ModelUtil $modelUtil, SourceFactory $sourceFactory)
    {
        $this->activeBundles = System::getContainer()->getParameter('kernel.bundles');
        $this->fileUtil = $fileUtil;
        $this->modelUtil = $modelUtil;
        $this->sourceFactory = $sourceFactory;
    }

    public function initPalette($dc)
    {
        if (null === ($sourceModel = $this->modelUtil->findModelInstanceByPk($dc->table, $dc->id))) {
            return;
        }

        $fileType = $sourceModel->fileType;

        $dca = &$GLOBALS['TL_DCA'][$dc->table];

        switch ($fileType) {
            case self::FILETYPE_CSV:

                /** @var CSVFileSource $source */
                $source = $this->sourceFactory->createInstance($sourceModel->id);

                $options = [];
                $fields = $source->getHeadingLine();

                foreach ($fields as $index => $field) {
                    if ($sourceModel->csvHeaderRow) {
                        $options[' '.$index] = $field.' ['.$index.']';
                    } else {
                        $options[' '.$index] = '['.$index.']';
                    }
                }

                $dca['fields']['fieldMapping']['eval']['multiColumnEditor']['fields']['sourceValue']['inputType'] = 'select';
                $dca['fields']['fieldMapping']['eval']['multiColumnEditor']['fields']['sourceValue']['options'] = $options;
                $dca['fields']['fieldMapping']['eval']['multiColumnEditor']['fields']['sourceValue']['eval']['includeBlankOption'] = true;
                $dca['fields']['fileContent']['eval']['rte'] = 'ace';

                break;

            case self::FILETYPE_JSON:

                $dca['fields']['fieldMapping']['eval']['multiColumnEditor']['fields']['sourceValue']['inputType'] = 'text';
                $dca['fields']['fileContent']['eval']['rte'] = 'ace|json';

                break;

            default:
                break;
        }

        return $dc;
    }

    public function onLoadFileContent($value, $dc)
    {
        if (null === ($sourceModel = $this->modelUtil->findModelInstanceByPk('tl_entity_import_source', $dc->id))) {
            return '';
        }

        if ($sourceModel->type !== static::TYPE_FILE) {
            return '';
        }

        /** @var AbstractFileSource $source */
        $source = $this->sourceFactory->createInstance($dc->id);

        if ($sourceModel->fileType === static::FILETYPE_CSV) {
            return $source->getLinesFromFile(5);
        }

        return $source->getFileContent();
    }
}
