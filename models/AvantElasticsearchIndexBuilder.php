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
    protected $status;

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
            'index' => $this->documentIndexName,
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
                    '_index' => $document->index,
                    '_type'  => $document->type,
                ]
            ];

            $actionsAndMetadata['index']['_id'] = $document->id;
            $documentBatchParams['body'][] = $actionsAndMetadata;
            $documentBatchParams['body'][] = $document->body;
        }

        return $documentBatchParams;
    }

    public function createDocumentFromItemMetadata($itemId, $identifier, $itemFieldTexts, $itemFiles, $isPublic)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($identifier);
        $document = new AvantElasticsearchDocument($documentId);

        // Provide the document with data that has been cached here by the index builder to improve performance.
        $document->setInstallationParameters($this->installation);

        // Provide the document with access to facet definitions.
        $avantElasticsearchFacets = new AvantElasticsearchFacets();
        $document->setAvantElasticsearchFacets($avantElasticsearchFacets);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($itemId, $itemFieldTexts, $itemFiles, $isPublic);

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
        // TO-DO: Get the index name from configuration instead of hard-coded.
        $indexName = 'omeka';

        $params = ['index' => $this->documentIndexName];
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
        $documentId = $this->getDocumentIdForItem($identifier);
        $document = new AvantElasticsearchDocument($documentId);

        $params = [
            'id' => $document->id,
            'index' => $this->documentIndexName,
            'type' => $document->type
        ];

        if (!$this->avantElasticsearchClient->deleteDocument($params))
        {
            // TO-DO: Report this error to the user or log it and email it to the admin.
            $errorMessage = $this->avantElasticsearchClient->getLastError();
            throw new Exception($errorMessage);
        }
    }

    protected function fetchAllFiles($public = true)
    {
        try
        {
            $db = get_db();
            $table = $db->getTable('File');
            $select = $table->getSelect();
            $select->order('files.item_id ASC');

            if ($public)
            {
                // Note that a get of the Files table automatically joins with the Items table and so the
                // WHERE clause below works even though this code does not explicitly join the two tables.
                $select->where('items.public = 1');
            }
            $files = $table->fetchObjects($select);

            // Create an array indexed by item Id where each element contains an array of that
            // item's files. This will make it possible to very quickly find an item's files.
            $itemFiles = array();
            foreach ($files as $file)
            {
                $itemFiles[$file->item_id][] = $file;
            }
            return $itemFiles;
        }
        catch (Exception $e)
        {
            $files = array();
        }
        return $files;
    }

    protected function fetchAllItems($public = true)
    {
        try
        {
            $db = get_db();
            $table = $db->getTable('Item');
            $select = $table->getSelect();
            $select->order('items.id ASC');

            if ($public)
            {
                $select->where('items.public = 1');
            }

            $items = $table->fetchObjects($select);
        }
        catch (Exception $e)
        {
            $items = array();
        }
        return $items;
    }

    protected function fetchFieldTextsForAllItems($public = true)
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
                  record_type = 'Item' 
            ";

            if ($public)
            {
                $sql .= " AND public = 1";
            }

            $results = $db->query($sql)->fetchAll();
        }
        catch (Exception $e)
        {
            // TO-DO: Report exception
            $itemFieldTexts = null;
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

    public function getIndexDataFilename($name)
    {
        $filename = FILES_DIR . DIRECTORY_SEPARATOR . 'elasticsearch' . DIRECTORY_SEPARATOR . $name . '.json';
        return $filename;
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

    protected function logClientError()
    {
        $this->logError($this->avantElasticsearchClient->getLastError());
    }

    protected function logError($errorMessage)
    {
        $this->status['error'] = $errorMessage;
    }

    protected function logEvent($eventMessage)
    {
        $this->status['events'][] = $eventMessage;
    }

    public function performBulkIndexExport($filename, $limit = 0)
    {
        $json = '';
        $this->cacheInstallationParameters();

        // Get all the items for this installation.
        $items = $this->fetchAllItems();

        // Get the entire Files table once so that each document won't have to do a SQL query to get its item's files.
        $files = $this->fetchAllFiles();
        $fieldTextsForAllItems = $this->fetchFieldTextsForAllItems();

        $fileStats = array();
        $documentSizeTotal = 0;

        // The limit is only used during development so that we don't always have
        // to index all the items. It serves no purpose in a production environment
        $itemsCount = $limit == 0 ? count($items) : $limit;

        $identifierElementId = ItemMetadata::getIdentifierElementId();

        $this->logEvent(__('Begin exporting %s items', $itemsCount));

        for ($index = 0; $index < $itemsCount; $index++)
        {
            $itemId = $items[$index]->id;

            $itemFiles = array();
            if (isset($files[$itemId]))
            {
                $itemFiles = $files[$itemId];
            }

            foreach ($itemFiles as $itemFile)
            {
                $count = 1;
                $size = $itemFile->size;
                $mimeType = $itemFile->mime_type;
                if (isset($fileStats[$mimeType]))
                {
                    $count += $fileStats[$mimeType]['count'];
                    $size += $fileStats[$mimeType]['size'];
                }
                $fileStats[$mimeType]['count'] = $count;
                $fileStats[$mimeType]['size'] = $size;
            }

            // Create a document for the item.
            $item = $items[$index];
            $fieldTextsForItem = $fieldTextsForAllItems[$itemId];
            $identifier = $fieldTextsForItem[$identifierElementId][0]['text'];
            $document = $this->createDocumentFromItemMetadata($itemId, $identifier, $fieldTextsForItem, $itemFiles, $item->public);

            // Determine the size of the document in bytes.
            $documentJson = json_encode($document);
            $documentSize = strlen($documentJson);
            $documentSizeTotal += $documentSize;

            // Write the document as an object to the JSON array, separating each object by a comma.
            $separator = $index > 0 ? ',' : '';
            $json .= $separator . $documentJson;

            // Let PHP know that it can garbage-collect these objects.
            unset($items[$index]);
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
            $this->logEvent(__('&nbsp;&nbsp;&nbsp; %s - %s (%s MB)', $fileStat['count'], $key, number_format($fileStat['size'] / MB_BYTES, 2)));
        }

        // Write the JSON data to a file.
        $fileSize = number_format(strlen($json) / MB_BYTES, 2);
        $this->logEvent(__('Write data to %s (%s MB)', $filename, $fileSize));
        file_put_contents($filename, "[$json]");

        return $this->status;
    }

    public function performBulkIndexImport($filename, $deleteExistingIndex)
    {
        $this->logEvent(__('Begin bulk import'));

        // Verify that the import file exists.
        if (!file_exists($filename))
        {
            $this->logError(__("File %s was not found", $filename));
            return $this->status;
        }

        // Delete the existing index if requested.
        if ($deleteExistingIndex)
        {
            if (!$this->createNewIndex())
            {
                return $this->status;
            }
        }

        // Read the index file into an array of AvantElasticsearchDocument objects.
        $this->batchDocuments = json_decode(file_get_contents($filename), false);
        $this->batchDocumentsCount = count($this->batchDocuments);

        // Build a list of document sizes.
        foreach ($this->batchDocuments as $document)
        {
            $this->batchDocumentsSizes[] = strlen(json_encode($document));
        }

        // Perform the actual indexing.
        $this->logEvent(__('Begin indexing %s documents', $this->batchDocumentsCount));
        $this->performBulkIndexImportBatches();

        return $this->status;
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
}