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
    protected $indexingId;
    protected $indexingOperation;

    public function __construct()
    {
        parent::__construct();
        $this->avantElasticsearchClient = new AvantElasticsearchClient();
    }

    public function addItemToIndex($item)
    {
        // This method adds a new item to the index or updates an existing item in the index.
        $this->cacheInstallationParameters();

        $identifier = ItemMetadata::getItemIdentifier($item);
        $itemFieldTexts = $this->getItemFieldTexts($item);
        $itemFiles = $item->Files;
        $document = $this->createDocumentFromItemMetadata($item->id, $identifier, $itemFieldTexts, $itemFiles, $item->public);

        $params = [
            'id' => $document->id,
            'index' => $this->getNameOfActiveIndex(),
            'type' => $document->type,
            'body' => $document->body
        ];

        // Add the document to the index.
        if (!$this->avantElasticsearchClient->indexDocument($params))
        {
            // TO-DO: Report this error to the user or log it and email it to the admin.
            $errorMessage = $this->avantElasticsearchClient->getLastError();
            throw new Exception($errorMessage);
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

        $this->installation['integer_sort_fields'] = array_map('strtolower', SearchConfig::getOptionDataForIntegerSorting());
        $this->installation['installation_elements'] = $this->getElementsUsedByThisInstallation();
        $this->installation['contributor'] = ElasticsearchConfig::getOptionValueForContributor();
        $this->installation['contributor-id'] = ElasticsearchConfig::getOptionValueForContributorId();
        $this->installation['item_path'] = public_url('items/show/');
        $this->installation['files_path'] = public_url('files');

        $serverUrlHelper = new Zend_View_Helper_ServerUrl;
        $this->installation['server_url'] = $serverUrlHelper->serverUrl();
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

    public function createDocumentFromItemMetadata($itemId, $identifier, $itemFieldTexts, $itemFilesData, $isPublic)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($identifier);
        $document = new AvantElasticsearchDocument($this->getNameOfActiveIndex(), $documentId);

        // Provide the document with data that has been cached here by the index builder to improve performance.
        $document->setInstallationParameters($this->installation);

        // Provide the document with access to facet definitions.
        $avantElasticsearchFacets = new AvantElasticsearchFacets();
        $document->setAvantElasticsearchFacets($avantElasticsearchFacets);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($itemId, $itemFieldTexts, $itemFilesData, $isPublic);

        return $document;
    }

    protected function createIndex($indexName)
    {
        $avantElasticsearchMappings = new AvantElasticsearchMappings();

        $params = [
            'index' => $indexName,
            'body' => ['mappings' => $avantElasticsearchMappings->constructElasticsearchMapping()]
        ];

        if (!$this->avantElasticsearchClient->createIndex($params))
        {
            $this->logClientError();
            return false;
        }

        return true;
    }

    protected function createNewIndex()
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

        if ($this->createIndex($indexName))
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

    public function deleteItemFromIndex($item)
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

        if (!$this->avantElasticsearchClient->deleteDocument($params))
        {
            $errorMessage = $this->avantElasticsearchClient->getLastError();
            $className = get_class($this->avantElasticsearchClient->getLastException());
            if ($className != 'Elasticsearch\Common\Exceptions\Missing404Exception')
            {
                // TO-DO: Report this error to the user or silently log it and email it to the admin.
                throw new Exception($errorMessage);
            }
        }
    }

    protected function fetchFilesData($public = true)
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
            ";

            if ($public)
            {
                $sql .= " WHERE items.public = 1";
            }

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

    protected function fetchItemsData($public = true)
    {
        try
        {
            $db = get_db();
            $table = "{$db->prefix}items";

            $sql = "
                SELECT
                  id,
                  public
                FROM
                  $table
            ";

            if ($public)
            {
                $sql .= " WHERE public = 1";
            }

            $sql .=  " ORDER BY id";

            $items = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $items = array();
        }
        return $items;
    }

    protected function fetchFieldTextsForAllItems($firstItemId, $lastItemId, $public = true)
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

            if ($public)
            {
                $sql .= " AND public = 1";
            }

            $results = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            $itemFieldTexts = array();
        }

        foreach ($results as $index => $result)
        {
            $elementId = $result['element_id'];

            $elementsForIndex = $this->getElementsUsedByThisInstallation($public);
            if ($public && !array_key_exists($elementId, $elementsForIndex))
            {
                // Ignore private elements.
                continue;
            }

            $itemId = $result['record_id'];
            $text = $result['text'];
            $html = $result['html'];
            $itemFieldTexts[$itemId][$elementId][] = $this->createFieldText($text, $html);
        }

        return $itemFieldTexts;
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
        $fileName = $this->getIndexingFileNamePrefix($indexingId) . '-' . $indexingOperation . '.html';
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
        foreach ($this->installation['installation_elements'] as $elementId => $elasticsearchFieldName)
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
        $indexingId = isset($_POST['indexing_id']) ? $_POST['indexing_id'] : '';
        $indexName = isset($_POST['index_name']) ? $_POST['index_name'] : '';
        $indexingOperation = isset($_POST['operation']) ? $_POST['operation'] : '';
        $indexingAction = false;
        $response = '';

        $memoryStart = memory_get_usage() / MB_BYTES;

        try
        {
            switch ($action)
            {
                case 'export-all':
                case 'export-some':
                    $indexingAction = true;
                    $limit = $action == 'export-all' ? 0 : 100;
                    $this->performBulkIndexExport($indexingId, $indexingOperation, $limit);
                    break;

                case 'import-new':
                case 'import-existing':
                    $indexingAction = true;
                    $deleteExistingIndex = $action == 'import-new';
                    $this->performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex);
                    break;

                case 'progress':
                    $response = $this->readLog($indexingId, $indexingOperation);
                    break;

                default:
                    $response = 'Unexpected action: ' . $action;
            }
        }
        catch (Exception $e)
        {
            $indexingAction = false;
            $response = $e->getMessage();
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

            $response = $this->readLog($indexingId, $indexingOperation);
        }

        $response = json_encode($response);
        echo $response;
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
        $event =  '<BR/>' . $eventMessage;
        file_put_contents($this->getIndexingLogFileName($this->indexingId, $this->indexingOperation), $event, FILE_APPEND);
    }

    public function performBulkIndexExport($indexingId, $indexingOperation, $limit = 0)
    {
        $this->indexingId = $indexingId;
        $this->indexingOperation = $indexingOperation;
        $this->logCreateNew();
        $this->logEvent(__('Start exporting'));

        $json = '';
        $this->cacheInstallationParameters();

        // Get all the items for this installation.
        $this->logEvent(__('Fetch items data from SQL database'));
        $itemsData = $this->fetchItemsData();
        if (empty($itemsData))
        {
            $this->logError('Failed to fetch items data from SQL database');
            return;
        }

        // Get the entire Files table once so that each document won't have to do a SQL query to get its item's files.
        $this->logEvent(__('Fetch file data from SQL database'));
        $files = $this->fetchFilesData();
        if (empty($files))
        {
            $this->logError('Failed to fetch file data from SQL database');
            return;
        }

        $fileStats = array();
        $documentSizeTotal = 0;

        // The limit is only used during development so that we don't always have
        // to index all the items. It serves no purpose in a production environment
        $itemsCount = $limit == 0 ? count($itemsData) : $limit;

        $identifierElementId = ItemMetadata::getIdentifierElementId();

        $this->logEvent(__('Begin exporting %s items', $itemsCount));

        $firstItemId = 0;
        $lastItemId = 0;

        for ($index = 0; $index < $itemsCount; $index++)
        {
            $chunkSize = 1000;
            if ($index % $chunkSize == 0)
            {
                $firstItemId = $index;
                $lastItemId = min($itemsCount - 1, $index + $chunkSize - 1);
                $this->logEvent(__('Exporting items %s - %s', $firstItemId + 1, $lastItemId));

                // Get all the field texts for this chunk of items.
                $itemFieldTextsForChunk = $this->fetchFieldTextsForAllItems($itemsData[$firstItemId]['id'], $itemsData[$lastItemId]['id']);
                if (empty($itemFieldTextsForChunk))
                {
                    $this->logError('Failed to fetch element texts from SQL database');
                    return;
                }
            }

            $itemData = $itemsData[$index];
            $itemId = $itemData['id'];

            $itemFilesData = array();
            if (isset($files[$itemId]))
            {
                $itemFilesData = $files[$itemId];
            }

            foreach ($itemFilesData as $itemFileData)
            {
                $count = 1;
                $size = $itemFileData['size'];
                $mimeType = $itemFileData['mime_type'];
                if (isset($fileStats[$mimeType]))
                {
                    $count += $fileStats[$mimeType]['count'];
                    $size += $fileStats[$mimeType]['size'];
                }
                $fileStats[$mimeType]['count'] = $count;
                $fileStats[$mimeType]['size'] = $size;
            }

            // Create a document for the item.
            $itemFieldsTexts = $itemFieldTextsForChunk[$itemId];
            $identifier = $itemFieldsTexts[$identifierElementId][0]['text'];
            $document = $this->createDocumentFromItemMetadata($itemId, $identifier, $itemFieldsTexts, $itemFilesData, $itemData['public']);

            // Determine the size of the document in bytes.
            $documentJson = json_encode($document);
            $documentSize = strlen($documentJson);
            $documentSizeTotal += $documentSize;

            // Write the document as an object to the JSON array, separating each object by a comma.
            $separator = $index > 0 ? ',' : '';
            $json .= $separator . $documentJson;

            // Let PHP know that it can garbage-collect these objects.
            unset($itemsData[$index]);
            if (isset($files[$itemId]))
            {
                unset($files[$itemId]);
            }
            unset($document);
        }

        // Report statistics.
        $this->logEvent(__('Export complete. %s items totaling %s MB', $itemsCount, number_format($documentSizeTotal / MB_BYTES, 2)));
        $this->logEvent(__('File Attachments:'));
        foreach ($fileStats as $key => $fileStat)
        {
            $this->logEvent(__('%s - %s (%s MB)', $fileStat['count'], $key, number_format($fileStat['size'] / MB_BYTES, 2)));
        }

        // Write the JSON data to a file.
        $fileSize = number_format(strlen($json) / MB_BYTES, 2);
        $dataFileName = $this->getIndexingDataFileName($this->indexingId);
        $this->logEvent(__('Write data to %s (%s MB)', $dataFileName, $fileSize));
        file_put_contents($dataFileName, "[$json]");
    }

    public function performBulkIndexImport($indexName, $indexingId, $indexingOperation, $deleteExistingIndex)
    {
        $this->setIndexName($indexName);
        $this->indexingId = $indexingId;
        $this->indexingOperation = $indexingOperation;
        $this->logCreateNew();
        $this->logEvent(__('Start importing'));

        $dataFileName = $this->getIndexingDataFileName($indexingId);

        // Verify that the import file exists.
        if (!file_exists($dataFileName))
        {
            $this->logError(__("File %s was not found", $dataFileName));
            return;
        }

        // Delete the existing index if requested.
        if ($deleteExistingIndex)
        {
            if (!$this->createNewIndex())
            {
                return;
            }
        }

        // Read the index file into an array of AvantElasticsearchDocument objects.
        $this->batchDocuments = json_decode(file_get_contents($dataFileName), false);
        $this->batchDocumentsCount = count($this->batchDocuments);

        // Build a list of document sizes.
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
        $limit = MB_BYTES * 2;
        $start = 0;
        $end = 0;
        $last = $this->batchDocumentsCount - 1;

        while ($start < $last)
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
            $this->logEvent(__('Indexing %s documents: %s - %s (%s MB)', $end - $start + 1, $start, $end, $batchSizeMb));

            // Perform the actual indexing on this batch of documents.
            $documentBatchParams = $this->createDocumentBatchParams($start, $end);
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
}