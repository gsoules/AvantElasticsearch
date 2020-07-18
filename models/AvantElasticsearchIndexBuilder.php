<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
    private $installation;

    /* @var $avantElasticsearchClient AvantElasticsearchClient  */
    protected $avantElasticsearchClient;
    protected $batchDocuments = array();
    protected $batchDocumentsCount;
    protected $batchDocumentsSizes = array();
    protected $batchDocumentsTotalSize;
    protected $document;
    protected $fileStats;
    protected $indexingId;
    protected $indexingOperation;
    protected $sqlFieldTextsData;
    protected $sqlFilesData;
    protected $sqlItemsData;
    protected $sqlTagsData;
    protected $vocabularies;

    public function __construct()
    {
        parent::__construct();
        $this->avantElasticsearchClient = new AvantElasticsearchClient();
        $this->vocabularies = $this->createVocabularies();
    }

    public function addItemToIndex($item, $isSharedIndex, $excludePrivateFields)
    {
        // This method adds a new item to the index or updates an existing item in the index.
        $this->cacheInstallationParameters();

        $identifier = ItemMetadata::getItemIdentifier($item);
        $itemFieldTexts = $this->getItemFieldTexts($item);
        $itemFilesData =  $this->getItemFilesData($item);
        $itemTagsData = $this->getItemTagsData($item);
        $itemData['id'] = $item->id;
        $itemData['identifier'] = $identifier;
        $itemData['field_texts'] = $itemFieldTexts;
        $itemData['files_data'] = $itemFilesData;
        $itemData['tags_data'] = $itemTagsData;
        $itemData['public'] = $item->public;
        $itemData['modified'] = $item->modified;

        $document = $this->createDocumentFromItemMetadata($itemData, $excludePrivateFields);

        // Fixup the document to use the appropriate data for a shared or local index.
        AvantElasticsearchDocument::fixupDocumentBody($isSharedIndex, $document->body);

        $params = [
            'id' => $document->id,
            'index' => $this->getNameOfActiveIndex(),
            'type' => $document->type,
            'body' => $document->body
        ];

        // Add the document to the index.
        $this->avantElasticsearchClient->indexDocument($params);
    }

    protected function cacheInstallationParameters()
    {
        // Perform expensive operations, many of which involve SQL queries to get and cache data that every document
        // will use during its creation. Doing this significantly improves performance when creating documents for
        // all the items in the installation. When enhancing this index builder or AvantElasticsearchDocument, be
        // be very careful not to introduce logic that uses expensive method calls each time a document is created.
        // Instead, whenever possible, make the calls just once here so that they get cached. By caching this data,
        // the time to create 10,000 documents was reduced by 75%.

        $integerSortFields = SearchConfig::getOptionDataForIntegerSorting();
        foreach ($integerSortFields as $index => $elementName)
            $integerSortFields[$index] = $this->convertElementNameToElasticsearchFieldName($elementName);
        $this->installation['integer_sort_fields'] = $integerSortFields;

        $this->installation['all_contributor_fields'] = $this->getFieldNamesOfAllElements();
        $this->installation['private_fields'] = $this->getFieldNamesOfPrivateElements();
        $this->installation['core_fields'] = $this->getFieldNamesOfCoreElements();
        $this->installation['local_fields'] = $this->getFieldNamesOfLocalElements();
        $this->installation['contributor'] = ElasticsearchConfig::getOptionValueForContributor();
        $this->installation['contributor_id'] = ElasticsearchConfig::getOptionValueForContributorId();
        $this->installation['alias_id'] = CommonConfig::getOptionDataForIdentifierAlias();
        $this->installation['item_path'] = public_url('items/show/');
        $this->installation['files_path'] = public_url('files');
        $this->installation['vocabularies'] = $this->vocabularies;

        $serverUrlHelper = new Zend_View_Helper_ServerUrl;
        $this->installation['server_url'] = $serverUrlHelper->serverUrl();
    }

    protected function compileItemFilesStats(array $itemFilesData)
    {
        // Gather statistics for this item's files. This is for reporting purposes only.
        foreach ($itemFilesData as $itemFileData)
        {
            $count = 1;
            $size = $itemFileData['size'];
            $mimeType = $itemFileData['mime_type'];
            if (isset($this->fileStats[$mimeType]))
            {
                $count += $this->fileStats[$mimeType]['count'];
                $size += $this->fileStats[$mimeType]['size'];
            }
            $this->fileStats[$mimeType]['count'] = $count;
            $this->fileStats[$mimeType]['size'] = $size;
        }
    }

    protected function convertDocumentForLocalOrSharedIndex($documentJson, $isSharedIndex, $coreFieldNames)
    {
        // This method changes the Json for a document into a document object that is
        // appropriate for either the local or shared index.

        if ($isSharedIndex)
        {
            // Exclude non-public items from the the shared index.
            // Remove private elements from documents in the shared index.
            //
            // This filtering is necessary to support the overall indexing approach which is to:
            // * 1.  Export all items and all fields into a single JSON data file containing 100% of the contributor's data
            // * 2a. Import only the non-private fields of public items into the shared index (using this method)
            // * 2b. Import all items and all fields into the local index (no filtering)
            //
            // This export once, import twice approach makes it possible to create/update both the local and shared indexes
            // from the same export file (versus having one export file for the local index and another for the shared index).

            if (!$documentJson['body']['item']['public'])
            {
                // Don't return a document for this non-public item.
                return null;
            }

            // Remove sorting for all but the core fields.
            foreach ($documentJson['body']['sort-shared-index'] as $fieldName => $sortValue)
            {
                if (!in_array($fieldName, $coreFieldNames))
                {
                    unset($documentJson['body']['sort-shared-index'][$fieldName]);
                }
            }

            // Remove all the private fields.
            unset($documentJson['body']['private-fields']);
        }

        // Fixup the document to use the appropriate data for a shared or local index.
        AvantElasticsearchDocument::fixupDocumentBody($isSharedIndex, $documentJson['body']);

        // Convert the array into an object.
        return (object)$documentJson;
    }

    public function createDocumentBatchParams($start, $end)
    {
        $documentBatchParams = ['body' => []];

        for ($index = $start; $index <= $end; $index++)
        {
            $document = $this->batchDocuments[$index];

            $actionsAndMetadata = [
                'index' => [
                    '_index' => $this->getNameOfActiveIndex(),
                    '_type'  => $document->type,
                ]
            ];

            $actionsAndMetadata['index']['_id'] = $document->id;
            $documentBatchParams['body'][] = $actionsAndMetadata;
            $documentBatchParams['body'][] = $document->body;
        }

        return $documentBatchParams;
    }

    public function createDocumentFromItemMetadata($itemData, $excludePrivateFields)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($itemData['identifier']);
        $document = new AvantElasticsearchDocument($this->getNameOfActiveIndex(), $documentId);

        // Provide the document with data that has been cached here by the index builder to improve performance.
        $document->setInstallationParameters($this->installation);

        // Provide the document with access to facet definitions.
        $avantElasticsearchFacets = new AvantElasticsearchFacets();
        $document->setAvantElasticsearchFacets($avantElasticsearchFacets);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($itemData, $excludePrivateFields);

        return $document;
    }

    protected function createIndex($indexName, $isSharedIndex)
    {
        $avantElasticsearchMappings = new AvantElasticsearchMappings();

        $settings = $avantElasticsearchMappings->constructElasticsearchSettings();
        $mappings = $avantElasticsearchMappings->constructElasticsearchMappings($isSharedIndex);

        $params = [
            'index' => $indexName,
            'body' => [
                'settings' => $settings,
                'mappings' => $mappings]
        ];

        if (!$this->avantElasticsearchClient->createIndex($params))
        {
            $this->logClientError();
            return false;
        }

        return true;
    }

    protected function createItemData($index, $identifierElementId)
    {
        $itemData = $this->sqlItemsData[$index];
        $itemId = $itemData['id'];

        // Get the files data for the current item.
        $itemFilesData = array();
        if (isset($this->sqlFilesData[$itemId]))
        {
            $itemFilesData = $this->sqlFilesData[$itemId];
        }
        $this->compileItemFilesStats($itemFilesData);

        // Get the tags data for the current item.
        $itemTagsData = array();
        if (isset($this->sqlTagsData[$itemId]))
        {
            $itemTagsData = $this->sqlTagsData[$itemId];
        }

        $itemFieldTexts = $this->sqlFieldTextsData[$itemId];

        // Create a document for this item.
        $itemData['identifier'] = $itemFieldTexts[$identifierElementId][0]['text'];
        $itemData['field_texts'] = $itemFieldTexts;
        $itemData['files_data'] = $itemFilesData;
        $itemData['tags_data'] = $itemTagsData;
        return $itemData;
    }

    protected function createNewIndex($isSharedIndex)
    {
        $indexName = $this->getNameOfActiveIndex();
        $params = ['index' => $indexName];
        if ($this->avantElasticsearchClient->deleteIndex($params))
        {
            $this->logEvent(__('Deleted index: %s', $indexName));
        }
        else
        {
            $this->logClientError();
            return false;
        }

        if ($this->createIndex($indexName, $isSharedIndex))
        {
            $this->logEvent(__('Created new index: %s', $indexName));
        }
        else
        {
            $this->logClientError();
            return false;
        }

        return true;
    }

    public function createVocabularies()
    {
        // This method creates arrays that provide fast access to vocabulary data so that code that needs
        // that data does not have to perform expensive SQL queries to get it. All the work is done here
        // just once when AvantElasticsearcIndexBuilder is constructed.

        if (!plugin_is_active('AvantVocabulary'))
            return null;

        // Get a table that associates vocabulary kinds with element Ids.
        $kindTable = AvantVocabulary::getVocabularyKinds();
        $mappings = array();

        try
        {
            // Query the database to get an array of site term items for each kind.
            foreach ($kindTable as $kind)
               $siteTermItemsForKind[$kind] = get_db()->getTable('VocabularySiteTerms')->getSiteTermItems($kind);

            // Convert the items into an array for each kind where the index is the site term and the value
            // is the common term. If there is no site term, the common term is used for the site term.
            foreach ($siteTermItemsForKind as $kind => $siteTermItems)
            {
                foreach ($siteTermItems as $siteTermItem)
                {
                    $commonTerm = $siteTermItem['common_term'];
                    $siteTerm = $siteTermItem['default_term'];
                    $mappings[$kind][$siteTerm] = $commonTerm;
                }
            }
        }
        catch (Exception $e)
        {
            // This should never happen under normal circumstances.
            $kindTable = array();
        }

        $vocabularies = [
            'kinds' => $kindTable,
            'mappings' => $mappings
        ];

        return $vocabularies;
    }

    public function deleteItemFromIndex($item, $failedAttemptOk = false)
    {
        $identifier = ItemMetadata::getItemIdentifier($item);

        if (empty($identifier))
        {
            throw new Exception("Unable to delete item from the Elasticsearch index.
            This can happen with Batch Delete. Try deactivating the AvantElasticsearch plugin.");
        }

        $documentId = $this->getDocumentIdForItem($identifier);
        $document = new AvantElasticsearchDocument($this->getNameOfActiveIndex(), $documentId);

        $params = [
            'id' => $document->id,
            'index' => $this->getNameOfActiveIndex(),
            'type' => $document->type
        ];

        $this->avantElasticsearchClient->deleteDocument($params, $failedAttemptOk);
    }

    protected function fetchDataFromSqlDatabase()
    {
        // This method performs a small number of SQL queries to obtain large amounts of data all at once.
        // This saves having to make thousands of calls to SQL server as would be required if SQL queries
        // were used for each document to be exported.

        // Get all the items for this installation.
        $this->logEvent(__('Fetch items data from SQL database'));
        $this->sqlItemsData = $this->fetchItemsData();
        if (empty($this->sqlItemsData))
        {
            $this->logError('Failed to fetch items data from SQL database');
            return false;
        }

        // Get the files data for all items so that each document won't do a SQL query to get its item's file data.
        $this->logEvent(__('Fetch file data from SQL database'));
        $this->sqlFilesData = $this->fetchFilesData();
        if (empty($this->sqlFilesData))
        {
            $this->logEvent('No file data fetched from SQL database');
        }

        // Get the tags for all items so that each document won't do a SQL query to get its item's tags.
        $this->logEvent(__('Fetch tag data from SQL database'));
        $this->sqlTagsData = $this->fetchTagsData();
        if (empty($this->sqlTagsData))
        {
            $this->logEvent('No tags fetched from SQL database');
        }

        return true;
    }

    protected function fetchFieldTextsDataFromSqlDatabase($index, $itemsCount)
    {
        // Get field texts for a chunk of items. Chunking uses a fraction of the memory necessary to get all texts.
        $chunkSize = 1000;
        if ($index % $chunkSize == 0)
        {
            $firstItemId = $index;
            $lastItemId = min($itemsCount - 1, $index + $chunkSize - 1);
            $this->logEvent(__('Exporting items %s - %s', $firstItemId + 1, $lastItemId));

            $this->sqlFieldTextsData = $this->fetchFieldTextsForRangeOfItems($this->sqlItemsData[$firstItemId]['id'], $this->sqlItemsData[$lastItemId]['id']);
            if (empty($this->sqlFieldTextsData))
            {
                $this->logError('Failed to fetch field texts from SQL database');
                return false;
            }
        }

        return true;
    }

    protected function fetchFilesData()
    {
        try
        {
            $db = get_db();
            $table = "{$db->prefix}files";

            $sql = "
                SELECT
                  item_id,
                  size,
                  has_derivative_image,
                  mime_type,
                  filename,
                  original_filename
                FROM
                  $table
                INNER JOIN
                  {$db->prefix}items AS items ON items.id = item_id
                ORDER BY
                  $table.order  
            ";

            $files = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $files = array();
        }

        // Create an array indexed by item Id where each element contains an array of that
        // items files. This will make it possible to very quickly find an items files.
        $itemFilesData = array();
        foreach ($files as $file)
        {
            $itemFilesData[$file['item_id']][] = $file;
        }

        return $itemFilesData;
    }

    protected function fetchItemsData()
    {
        try
        {
            $db = get_db();
            $table = "{$db->prefix}items";

            $sql = "
                SELECT
                  id,
                  public,
                  modified
                FROM
                  $table
            ";

            $sql .=  " ORDER BY id";

            $items = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $items = array();
        }
        return $items;
    }

    protected function fetchFieldTextsForRangeOfItems($firstItemId, $lastItemId)
    {
        // This method gets all element texts for all items in the database. It returns them as an array of item-field-texts.
        // * Each item-field-texts contains an array of field-texts, one for each of the item's elements.
        // * Each field-texts contains an array of field-text, one for each of the element's values.
        // * Each field-text contains two values: the element value text and a flag to indicate if the the text is HTML.
        //
        // The html flag is only true when the user entered text into an Omeka element that displays the HTML checkbox
        // on the admin Edit page AND they checked the box. Note that an element can have multiple values with some as
        // HTML and others as plain text, thus the need to have the flag for each field-text. Knowledge of whether the
        // text is HTML is important when displaying search results because HTML text has to be displayed as HTML, not
        // as plain text containing HTML tags.
        //
        // We use the term field texts to differentiate from the Omeka ElementText object which contains much more
        // information than is needed for creating Elasticsearch fields. The SQL below fetches only the text and html
        // columns from the element_texts table instead of returning an entire ElementText object as would happen if
        // the code called fetchObjects().

        $itemFieldTexts = array();
        $results = array();

        try
        {
            $db = get_db();
            $table = "{$db->prefix}element_texts";

            $sql = "
                SELECT
                  record_id,
                  element_id,
                  text,
                  html
                FROM
                  $table
                INNER JOIN
                  {$db->prefix}items AS items ON items.id = record_id
                WHERE
                  record_type = 'Item' AND record_id >= $firstItemId AND record_id <= $lastItemId
            ";

            $results = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $itemFieldTexts = array();
        }

        foreach ($results as $index => $result)
        {
            $elementId = $result['element_id'];
            $itemId = $result['record_id'];
            $text = $result['text'];
            $html = $result['html'];
            $itemFieldTexts[$itemId][$elementId][] = $this->createFieldText($text, $html);
        }

        return $itemFieldTexts;
    }

    protected function fetchTagsData()
    {
        try
        {
            $db = get_db();
            $table = "{$db->prefix}records_tags";

            $sql = "
                SELECT
                  record_id,
                  name
                FROM
                  $table
                INNER JOIN
                  {$db->prefix}tags AS tags ON tags.id = tag_id
                ORDER BY
                  record_id
            ";

            $tags = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $tags = array();
        }

        // Create an array indexed by item Id where each element contains an array of that
        // items tags. This will make it possible to very quickly find an items tags.
        $itemTagsData = array();
        foreach ($tags as $tag)
        {
            $itemTagsData[$tag['record_id']][] = $tag['name'];
        }

        return $itemTagsData;
    }

    protected function freeSqlData($itemId, $index)
    {
        // Tell PHP it can garbage-collect these objects. This reduces peak memory usage by 15% on export of 10,000 items.
        unset($this->sqlItemsData[$index]);
        if (isset($this->sqlFilesData[$itemId]))
        {
            unset($this->sqlFilesData[$itemId]);
        }
        unset($this->document);
    }

    public function getElasticsearchFilesDirectoryName()
    {
        return FILES_DIR . DIRECTORY_SEPARATOR . 'elasticsearch';
    }

    protected function getIndexingDataFileName($indexingId)
    {
        return $this->getIndexingFileNamePrefix($indexingId) . '.json';
    }

    protected function getIndexingLogFileName($indexingId, $indexingOperation)
    {
        $fileName = $this->getIndexingFileNamePrefix($indexingId) . '-' . $indexingOperation . '.log';
        return $fileName;
    }

    protected function getIndexingFileNamePrefix($indexingId)
    {
        return $this->getElasticsearchFilesDirectoryName() . DIRECTORY_SEPARATOR . $indexingId;
    }

    protected function getItemFieldTexts($item)
    {
        // Get all the elements and all element texts.
        $allElementTexts = get_db()->getTable('ElementText')->findByRecord($item);
        $fieldTexts = array();

        // Loop over the elements and for each one, find its text value(s).
        foreach ($this->installation['all_contributor_fields'] as $elementId => $fieldName)
        {
            foreach ($allElementTexts as $elementText)
            {
                if ($elementText->element_id == $elementId)
                {
                    $fieldTexts[$elementId][] = $this->createFieldText($elementText->text, $elementText->html);
                }
            }
        }

        return $fieldTexts;
    }

    protected function getItemFilesData($item)
    {
        $itemFiles = $item->Files;
        $itemFilesData = array();

        foreach ($itemFiles as $file)
        {
            $fileData['size'] = $file->size;
            $fileData['has_derivative_image'] = $file->hasThumbnail();
            $fileData['mime_type'] = $file->mime_type;
            $fileData['filename'] = $file->filename;
            $fileData['original_filename'] = $file->original_filename;
            $itemFilesData[] = $fileData;
        }

        return $itemFilesData;
    }

    protected function getItemTagsData($item)
    {
        $tagsData = array();
        foreach ($item->getTags() as $tag)
        {
            $tagsData[] = $tag->name;
        }
        return $tagsData;
    }

    function handleAjaxRequest()
    {
        // This method is called in response to Ajax requests from the client. It is called just once for
        // each import or export action, but it is called multiple times for the progress action, every few
        // seconds, for as long as the import or export is executing. The instance of AvantElasticsearchIndexBuilder
        // for an import or export action is not the same object as the ones for the progress actions. As such, the
        // import and export instances do not share memory with the progress instances and cannot share the record
        // of logged events via a class variable. Instead, the events are written to a log file by the import and
        // export instances and read by the progress instances. While file I/O is expensive, this approach has the
        // benefit of providing a persistent log file which can be used later to view import and export statistics.

        $action = isset($_POST['action']) ? $_POST['action'] : 'NO ACTION PROVIDED';
        $contributorId = ElasticsearchConfig::getOptionValueForContributorId();
        $indexingId = isset($_POST['indexing_id']) ? $_POST['indexing_id'] : '';
        $indexingOperation = isset($_POST['operation']) ? $_POST['operation'] : '';
        $indexingAction = false;
        $response = '';
        $success = true;

        $memoryStart = memory_get_usage() / MB_BYTES;

        try
        {
            switch ($action)
            {
                case 'export-all':
                case 'export-some':
                    $indexingAction = true;
                    // The limit is used only during development to reduce the number of items exported when debugging.
                    $limit = $action == 'export-all' ? 0 : 100;
                    $this->performBulkIndexExport($contributorId, $indexingId, $indexingOperation, $limit);
                    break;

                case 'import-local-new':
                case 'import-local-existing':
                case 'import-shared-new':
                case 'import-shared-existing':
                case 'remove-shared':
                    $indexingAction = true;
                    $deleteExistingIndex = $action == 'import-local-new' || $action == 'import-shared-new';
                    if ($action == 'import-local-new' || $action == 'import-local-existing')
                        $indexName = ElasticsearchConfig::getOptionValueForContributorId();
                    else
                        $indexName = AvantElasticsearch::getNameOfSharedIndex();

                    if ($action == 'remove-shared')
                    {
                        $this->removeItemsFromSharedIndex($indexName, $indexingId, $indexingOperation);
                    }
                    else
                    {
                        $this->performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex);
                    }
                    break;

                case 'progress':
                    $response = $this->readLog($indexingId, $indexingOperation);
                    break;

                default:
                    $response = 'Unexpected action: ' . $action;
                    $success = false;
            }
        }
        catch (Exception $e)
        {
            $indexingAction = false;
            $response = $e->getMessage();
            $success = false;
        }

        if ($indexingAction)
        {
            $memoryEnd = memory_get_usage() / MB_BYTES;
            $memoryUsed = $memoryEnd - $memoryStart;
            $this->logEvent(__('Memory used: %s MB', number_format($memoryUsed, 2)));

            $peakMemoryUsage = memory_get_peak_usage() /  MB_BYTES;
            $this->logEvent(__('Peak usage: %s MB', number_format($peakMemoryUsage, 2)));

            $executionSeconds = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
            $time = $executionSeconds == 0 ? '< 1 second' : "$executionSeconds seconds";
            $this->logEvent(__('Execution time: %s', $time));
            $this->logEvent(__('DONE'));

            $response = $this->readLog($indexingId, $indexingOperation);
        }

        $response = json_encode(array('success'=>$success, 'message'=>$response));
        echo $response;
    }

    public function hasVocabularies()
    {
        $has = false;
        if ($this->vocabularies)
        {
            $has = !empty($this->vocabularies['mappings']);
        }
        return $has;
    }

    protected function initializeIndexingOperation($indexName, $indexingId, $indexingOperation)
    {
        $this->setIndexName($indexName);
        $this->indexingId = $indexingId;
        $this->indexingOperation = $indexingOperation;
        $this->logCreateNew();
        $this->logEvent(__('Start %s', $indexingOperation));
    }

    protected function logCreateNew()
    {
        // Create a new log file (overwrite an existing log file with the same name).
        file_put_contents($this->getIndexingLogFileName($this->indexingId, $this->indexingOperation), date("Y-m-d H:i:s"));
    }

    protected function logClientError()
    {
        $this->logError($this->avantElasticsearchClient->getLastError());
    }

    protected function logError($errorMessage)
    {
        $this->logEvent("<span class='indexing-error'>$errorMessage</span>");
    }

    protected function logEvent($eventMessage)
    {
        $event =  PHP_EOL . $eventMessage;
        file_put_contents($this->getIndexingLogFileName($this->indexingId, $this->indexingOperation), $event, FILE_APPEND);
    }

    public function performBulkIndexExport($indexName, $indexingId, $indexingOperation, $limit = 0)
    {
        $this->initializeIndexingOperation($indexName, $indexingId, $indexingOperation);
        $this->cacheInstallationParameters();

        if (!$this->fetchDataFromSqlDatabase())
            return;

        $maxCount = count($this->sqlItemsData);
        $itemsCount = $limit == 0 ? $maxCount : min($limit, $maxCount);

        $this->fileStats = array();
        $identifierElementId = ItemMetadata::getIdentifierElementId();

        // Derive the data file name and delete it if it already exists.
        $dataFileName = $this->getIndexingDataFileName($this->indexingId);
        unlink($dataFileName);

        $this->logEvent(__('Begin exporting %s items', $itemsCount));
        for ($index = 0; $index < $itemsCount; $index++)
        {
            if (!$this->fetchFieldTextsDataFromSqlDatabase($index, $itemsCount))
                return;

            // Create an Elasticsearch document for this item and encode it as JSON.
            $itemData = $this->createItemData($index, $identifierElementId);
            $excludePrivateFields = false;
            $this->document = $this->createDocumentFromItemMetadata($itemData, $excludePrivateFields);
            $this->writeDocumentToJsonData($dataFileName);
            $this->freeSqlData($itemData['id'], $index);
        }

        $this->reportExportStatistics($itemsCount, $dataFileName);
    }

    public function performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex)
    {
        $this->initializeIndexingOperation($indexName, $indexingId, $indexingOperation);
        $dataFileName = $this->getIndexingDataFileName($indexingId);
        $coreFieldNames = $this->getFieldNamesOfCoreElements();
        $isSharedIndex = $indexName == self::getNameOfSharedIndex();

        // Verify that the import file exists.
        if (!file_exists($dataFileName))
        {
                  $this->logError(__("File %s was not found", $dataFileName));
            return;
        }

        // Delete the existing index if requested.
        if ($deleteExistingIndex)
        {
            if (!$this->createNewIndex($isSharedIndex))
            {
                return;
            }
        }

        // Read the index file into an array of raw document data which contains local and shared information.
        $handle = fopen($dataFileName, "r");
        if ($handle)
        {
            while (($line = fgets($handle)) !== false)
            {
                $documentJson = json_decode($line, true);

                // Convert the data into a document object structured for either the local or shared index.
                $documentObject = $this->convertDocumentForLocalOrSharedIndex($documentJson, $isSharedIndex, $coreFieldNames);
                if ($documentObject)
                    $this->batchDocuments[] = $documentObject;
            }

            fclose($handle);
        }
        else
        {
            throw new Exception("Unable to open $dataFileName.");
        }

        // Build a list of document sizes.
        $this->batchDocumentsCount = count($this->batchDocuments);
        foreach ($this->batchDocuments as $document)
        {
            $this->batchDocumentsSizes[] = strlen(json_encode($document));
        }

        // Perform the actual indexing.
        $this->logEvent(__('Begin indexing %s documents', $this->batchDocumentsCount));
        $this->performBulkIndexImportBatches();
    }

    protected function performBulkIndexImportBatches()
    {
        $limit = MB_BYTES * 1;
        $start = 0;
        $end = 0;
        $last = $this->batchDocumentsCount - 1;

        while ($start <= $last)
        {
            $batchSize = 0;
            $limitReached = false;

            // Determine which subset of the document will fit in the next batch. When this loop ends, we know the
            // index of the first and last document to be indexed.
            while (!$limitReached && $end <= $last)
            {
                $documentSize = $this->batchDocumentsSizes[$end];

                if ($batchSize + $documentSize <= $limit || $batchSize == 0)
                {
                    // This document will fit within in the batch, or it's the first document in the batch in which case
                    // it's allowed even if its size exceeds the limit. Note that this logic does not handle the case
                    // where a single document exceeds the upload limit of 10 MB. In that case, an error will be
                    // reported and either that document's size will have to be reduced or this logic will have to
                    // somehow handle splitting a document across multiple uploads. 10 MB of text would be unusual.
                    $batchSize += $documentSize;
                    $end++;
                }
                else
                {
                    $limitReached = true;
                }
            }

            $end--;

            $this->batchDocumentsTotalSize += $batchSize;
            $batchSizeMb = number_format($batchSize / MB_BYTES, 2);
            $this->logEvent(__('Indexing %s documents: %s - %s (%s MB)', $end - $start + 1, $start + 1, $end + 1, $batchSizeMb));

            $documentBatchParams = $this->createDocumentBatchParams($start, $end);

            // Create a new client. While this should not be necessary, it's done to see if this will
            // prevent the No Alive Nodes exception from occurring due to a suspected bug in the client code.
            $this->avantElasticsearchClient = new AvantElasticsearchClient();

            // Perform the actual indexing on this batch of documents.
            if (!$this->avantElasticsearchClient->indexBulkDocuments($documentBatchParams))
            {
                $this->logClientError();
                return;
            }

            $end++;
            $start = $end;
        }

        $totalSizeMb = number_format($this->batchDocumentsTotalSize / MB_BYTES, 2);
        $this->logEvent(__("%s documents indexed (%s MB)", $this->batchDocumentsCount, $totalSizeMb));
    }

    protected function readLog($indexingId, $indexingOperation)
    {
        // The indexing Id gets passed because this method is called in response to an Ajax request for progress.
        // Because the caller is running in a different instance of AvantElasticsearchIndexBuilder than the instance
        // which is performing indexing, the caller's $this->indexingId class variable is not set for this method to
        // use. All of the other log methods are for writing, and all are called only by the instance doing the
        // indexing and that instance sets $this->indexingId when indexing first begins. Thus, the log write methods
        // don't need the indexing Id parameter.
        $logFileName = $this->getIndexingLogFileName($indexingId, $indexingOperation);
        return file_get_contents($logFileName);
    }

    protected function removeItemsFromSharedIndex($indexName, $indexingId, $indexingOperation)
    {
        // This method will remove all of a contributor's items from the shared index
        // without affecting items from other contributors.
        $this->initializeIndexingOperation($indexName, $indexingId, $indexingOperation);
        $this->logEvent(__('Begin removing items from shared index'));
        $contributorId = ElasticsearchConfig::getOptionValueForContributorId();

        $params = [
            'index' => $indexName,
            'type' => $this->getDocumentMappingType(),
            'body' => [
                'query' => [
                    'match' => [
                        'item.contributor-id' => $contributorId
                    ]
                ]
            ]
        ];

        return $this->avantElasticsearchClient->deleteDocumentsByContributor($params);
    }

    protected function reportExportStatistics($itemsCount, $fileName)
    {
        $this->logEvent(__('Export complete. %s items', $itemsCount));
        $this->logEvent(__('File Attachments:'));
        foreach ($this->fileStats as $key => $fileStat)
        {
            $this->logEvent(__('%s - %s (%s MB)', $fileStat['count'], $key, number_format($fileStat['size'] / MB_BYTES, 2)));
        }

        $fileSize = number_format(filesize($fileName) / MB_BYTES, 2);
        $logFileName = $this->getIndexingLogFileName($this->indexingId, $this->indexingOperation);
        $this->logEvent(__('%s MB written to %s', $fileSize, $fileName));
        $this->logEvent(__('Log file: %s', $logFileName));
    }

    protected function writeDocumentToJsonData($fileName)
    {
        // Write the document as an object to the JSON array, separating each object by a comma.
        $documentJson = json_encode($this->document);
        $json = $documentJson . "\n";
        file_put_contents($fileName, $json, FILE_APPEND);
    }
}