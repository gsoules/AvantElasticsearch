<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
    private $installation;

    /* @var $avantElasticsearchClient AvantElasticsearchClient  */
    protected $avantElasticsearchClient;

    protected $document;
    protected $fileStats;
    protected $indexingId;
    protected $indexingOperation;

    /* @var $log AvantElasticsearchLog  */
    protected $log;

    protected $sqlFieldTextsData;
    protected $sqlFilesData;
    protected $sqlItemsData;
    protected $sqlRelationshipsData;
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
        $itemData['relationships'] = $this->getItemRelationshipsCount($item);

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
        for ($attempt = 1; $attempt <= 3; $attempt++)
        {
            $success = $this->avantElasticsearchClient->indexDocument($params, $attempt);
            if ($success)
                break;
        }
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
            // Exclude non-public items from the shared index.
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
        $itemData['relationships'] = array_key_exists($itemId, $this->sqlRelationshipsData) ? $this->sqlRelationshipsData[$itemId] : 0;

        return $itemData;
    }

    protected function createNewIndex($isSharedIndex)
    {
        $indexName = $this->getNameOfActiveIndex();
        $params = ['index' => $indexName];
        if ($this->avantElasticsearchClient->deleteIndex($params))
        {
            $this->log->logEvent(__('Deleted index: %s', $indexName));
        }
        else
        {
            $this->logClientError();
            return false;
        }

        if ($this->createIndex($indexName, $isSharedIndex))
        {
            $this->log->logEvent(__('Created new index: %s', $indexName));
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

    public function deleteItemFromIndex($item, $missingDocumentExceptionOk = false)
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

        for ($attempt = 1; $attempt <= 3; $attempt++)
        {
            $success = $this->avantElasticsearchClient->deleteDocument($params, $missingDocumentExceptionOk, $attempt);
            if ($success)
                break;
        }
    }

    protected function fetchDataFromSqlDatabase()
    {
        // This method performs a small number of SQL queries to obtain large amounts of data all at once.
        // This saves having to make thousands of calls to SQL server as would be required if SQL queries
        // were used for each document to be exported.

        // Get all the items for this installation.
        $this->log->logEvent(__('Fetch items data from SQL database'));
        $this->sqlItemsData = $this->fetchItemsData();
        if (empty($this->sqlItemsData))
        {
            $this->log->logError('Failed to fetch items data from SQL database');
            return false;
        }

        // Get the files data for all items so that each document won't do a SQL query to get its item's file data.
        $this->log->logEvent(__('Fetch file data from SQL database'));
        $this->sqlFilesData = $this->fetchFilesData();
        if (empty($this->sqlFilesData))
        {
            $this->log->logEvent('No file data fetched from SQL database');
        }

        // Get the tags for all items so that each document won't do a SQL query to get its item's tags.
        $this->log->logEvent(__('Fetch tag data from SQL database'));
        $this->sqlTagsData = $this->fetchTagsData();
        if (empty($this->sqlTagsData))
        {
            $this->log->logEvent('No tags fetched from SQL database');
        }

        // Get the relationship counts for all items so that each document won't do a SQL query to get them.
        $this->log->logEvent(__('Fetch relationship count data from SQL database'));
        $this->sqlRelationshipsData = $this->fetchRelationshipsData();
        if (empty($this->sqlRelationshipsData))
        {
            $this->log->logEvent('No relationship counts fetched from SQL database');
        }

        return true;
    }

    protected function fetchFieldTextsDataFromSqlDatabase($index, $itemsCount)
    {
        // Get field texts for a chunk of items. Chunking uses a fraction of the memory necessary to get all texts.
        $chunkSize = 100;
        if ($index % $chunkSize == 0)
        {
            $firstItemId = $index;
            $lastItemId = min($itemsCount - 1, $index + $chunkSize - 1);
            $percentDone = round(($index / $itemsCount) * 100) . '%';
            $this->log->logEvent(__('%s - Exporting items %s - %s of %s',
                $percentDone, $firstItemId + 1, $lastItemId + 1, $itemsCount));

            $this->sqlFieldTextsData = $this->fetchFieldTextsForRangeOfItems($this->sqlItemsData[$firstItemId]['id'], $this->sqlItemsData[$lastItemId]['id']);
            if (empty($this->sqlFieldTextsData))
            {
                $this->log->logError('Failed to fetch field texts from SQL database');
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
        // * Each field-text contains two values: the element value text and a flag to indicate if the text is HTML.
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

    protected function fetchRelationshipsData()
    {
        $db = get_db();
        $table = "{$db->prefix}relationships";

        try
        {
            $sql = "
                SELECT
                  source_item_id as `id`,
                  count(source_item_id) as `count`
                FROM
                  $table
            ";

            $sql .=  " GROUP BY source_item_id";
            $sql .=  " ORDER BY source_item_id";

            $sourceItems = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $sourceItems = array();
        }

        try
        {
            $sql = "
                SELECT
                  target_item_id as `id`,
                  count(target_item_id) as `count`
                FROM
                  $table
            ";

            $sql .=  " GROUP BY target_item_id";
            $sql .=  " ORDER BY target_item_id";

            $targetItems = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $targetItems = array();
        }

        $items = array();
        foreach ($sourceItems as $sourceItem)
            $items[$sourceItem['id']] = $sourceItem['count'];

        foreach ($targetItems as $targetItem)
        {
            $id = $targetItem['id'];
            if (array_key_exists($id, $items))
            {
                $items[$id] = $items[$id] + $targetItem['count'];
            }
            else
            {
                $items[$id] = $targetItem['count'];
            }
        }

        return $items;
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

    protected function getItemRelationshipsCount($item)
    {
        $relatedItemsModel = new RelatedItemsModel($item);
        $relatedItems = $relatedItemsModel->getRelatedItems();
        return count($relatedItems);
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
                    $indexingAction = true;
                    $deleteExistingIndex = $action == 'import-local-new' || $action == 'import-shared-new';
                    if ($action == 'import-local-new' || $action == 'import-local-existing')
                        $indexName = ElasticsearchConfig::getOptionValueForContributorId();
                    else
                        $indexName = AvantElasticsearch::getNameOfSharedIndex();

                    if ($action == 'import-shared-existing')
                    {
                        // Removes all of this site's items from the shared index so that any items that may have
                        // gotten deleted from MySQL via a backdoor method, will get removed from the shared index.
                        // If this step is not performed, those deleted items will persist as ghosts that will appear
                        // in search results, but if you click on one, you'll get a 404 error because the item
                        // is not in the database.
                        $this->removeItemsFromSharedIndex($indexName, $indexingId, $indexingOperation);
                    }
                    $this->performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex);
                    break;

                case 'progress':
                    $response = AvantElasticsearchLog::readLog();
                    $response .= PHP_EOL;
                    $response .= AvantElasticsearchLog::readProgress();
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
            $this->log->logEvent(__('Memory used: %s MB', number_format($memoryUsed, 2)));

            $peakMemoryUsage = memory_get_peak_usage() /  MB_BYTES;
            $this->log->logEvent(__('Peak usage: %s MB', number_format($peakMemoryUsage, 2)));

            $executionSeconds = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
            $time = $executionSeconds == 0 ? '< 1 second' : "$executionSeconds seconds";
            $this->log->logEvent(__('Execution time: %s', $time));
            $this->log->logEvent(__('DONE'));
            $response = AvantElasticsearchLog::readLog();
            $this->log->writeLogToFile();
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
        $this->log = new AvantElasticsearchLog($this->getIndexingLogFileName($this->indexingId, $this->indexingOperation));
        $this->log->startNewLog();
        $this->log->logEvent((__('Start %s', $indexingOperation)));
    }

    protected function logClientError()
    {
        $this->log->logError($this->avantElasticsearchClient->getLastError());
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

        // Derive the export file's name and delete the file if it already exists.
        $exportFileName = $this->getIndexingDataFileName($this->indexingId);
        if (file_exists($exportFileName))
            unlink($exportFileName);

        $this->log->logEvent(__('Begin exporting %s items', $itemsCount));
        for ($index = 0; $index < $itemsCount; $index++)
        {
            if (!$this->fetchFieldTextsDataFromSqlDatabase($index, $itemsCount))
                return;

            // Create an Elasticsearch document for this item and encode it as JSON.
            $itemData = $this->createItemData($index, $identifierElementId);
            $excludePrivateFields = false;
            $this->document = $this->createDocumentFromItemMetadata($itemData, $excludePrivateFields);
            $this->writeDocumentToJsonData($exportFileName);
            $this->freeSqlData($itemData['id'], $index);
        }

        $this->reportExportStatistics($itemsCount, $exportFileName);
    }

    public function performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex)
    {
        $this->initializeIndexingOperation($indexName, $indexingId, $indexingOperation);
        $importFileName = $this->getIndexingDataFileName($indexingId);
        $coreFieldNames = $this->getFieldNamesOfCoreElements();
        $isSharedIndex = $indexName == self::getNameOfSharedIndex();

        // Verify that the import file exists.
        if (!file_exists($importFileName))
        {
            $this->log->logError(__("File %s was not found", $importFileName));
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

        $this->log->logEvent(__('Begin indexing documents'));

        $this->avantElasticsearchClient = new AvantElasticsearchClient();

        // Read the index file into an array of raw document data which contains local and shared information.
        $importFileHandle = fopen($importFileName, "r");
        if (!$importFileHandle)
            throw new Exception("Unable to open $importFileName.");

        $fileLineCount = count(file($importFileName));
        $documentCount = 0;
        $skipCount = 0;
        $batchLimit = MB_BYTES * 1;
        $documentBatchParams = array();
        $batchSize = 0;
        $batchStart = 0;
        $totalSize = 0;

        while (($line = fgets($importFileHandle)) !== false)
        {
            $documentCount += 1;

            if ($batchSize == 0)
            {
                $documentBatchParams = ['body' => []];
                $batchStart = $documentCount;
            }

            $json = json_decode($line, true);

            // Convert the json into a document object structured for either the local or shared index.
            $document = $this->convertDocumentForLocalOrSharedIndex($json, $isSharedIndex, $coreFieldNames);
            if ($document)
            {
                $batchSize += strlen($line);
                $totalSize += $batchSize;

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
            else
            {
                $skipCount += 1;
            }

            // Determine whether to index the current batch of documents. Do so when either the batch is at or above
            // the max batch size (it's okay for the last processed document to push the size over the max) or when
            // the last document has been processed and thus this is the last batch.
            if ($batchSize >= $batchLimit || $documentCount == $fileLineCount)
            {
                // Update the statistics and display a progress message. Note that the stats are for what is about to
                // be processed when indexBulkDocuments is called. As such, if an error occurs during indexing, the
                // stats would be reporting what was supposed to happen, even though an error occurred.
                $batchEnd = $documentCount;
                $batchSizeMb = number_format($batchSize / MB_BYTES, 2);
                $percentDone = round(($batchEnd / $fileLineCount) * 100) . '%';
                $this->log->logEvent(__('%s - Indexing %s documents (%s - %s of %s) %s MB',
                    $percentDone, $batchEnd - $batchStart + 1, $batchStart, $batchEnd, $fileLineCount, $batchSizeMb));

                // Index the batch, but only after verifying that there's something to index. There usually is, but
                // testing the batch size protects against the case where no documents got added to the batch which
                // can happen if all the items since the last batch was processed were private, but the last line of
                // JSON has been encountered. In that case, the stats get reported saying 100% done, but no call is
                // made to indexBulkDocuments.
                if ($batchSize > 0)
                {
                    if (!$this->avantElasticsearchClient->indexBulkDocuments($documentBatchParams))
                    {
                        $this->logClientError();
                        return;
                    }
                    $batchSize = 0;
                }
            }
        }

        fclose($importFileHandle);

        $totalSizeMb = number_format($totalSize / MB_BYTES, 2);
        $this->log->logEvent(__("%s documents indexed (%s MB)", $documentCount, $totalSizeMb));
        $this->log->logEvent(__("%s non-public documents skipped", $skipCount));
    }

    protected function removeItemsFromSharedIndex($indexName, $indexingId, $indexingOperation)
    {
        // This method will remove all of a contributor's items from the shared index
        // without affecting items from other contributors.
        $this->initializeIndexingOperation($indexName, $indexingId, $indexingOperation);
        $this->log->logEvent(__('Begin removing items from shared index'));
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
        $this->log->logEvent(__('Export complete. %s items', $itemsCount));
        $this->log->logEvent(__('File Attachments:'));
        foreach ($this->fileStats as $key => $fileStat)
        {
            $this->log->logEvent(__('%s - %s (%s MB)', $fileStat['count'], $key, number_format($fileStat['size'] / MB_BYTES, 2)));
        }

        $fileSize = number_format(filesize($fileName) / MB_BYTES, 2);
        $this->log->logEvent(__('%s MB written to %s', $fileSize, $fileName));
        $this->log->logEvent(__('Log file: %s', $this->log->getLogFileName()));
    }

    protected function writeDocumentToJsonData($fileName)
    {
        // Write the document as an object to the JSON array, separating each object by a comma.
        $documentJson = json_encode($this->document);
        $json = $documentJson . "\n";
        file_put_contents($fileName, $json, FILE_APPEND);
    }
}