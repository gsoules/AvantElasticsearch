<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
    private $installation;
    private $json = '';

    public function __construct()
    {
        parent::__construct();
    }

    protected function cacheInstallationParameters()
    {
        // Perform expensive operations, many of which involve SQL queries to get and cache data that every document
        // will use during its creation. Doing this significantly improves performance when creating documents for
        // all the items in the installation. When enhancing this index builder or AvantElasticsearchDocument, be
        // be very careful not to introduce logic that uses expensive method calls each time a document is created.
        // Instead, whenever possible, make the calls just once here so that they get cached. By caching this data,
        // the time to create 10,000 documents was reduced by 75%.

        $this->installation['integer_sort_elements'] = SearchConfig::getOptionDataForIntegerSorting();
        $this->installation['installation_elements'] = $this->getElementsUsedByThisInstallation();
        $this->installation['owner'] = ElasticsearchConfig::getOptionValueForOwner();
        $this->installation['ownerid'] = ElasticsearchConfig::getOptionValueForOwnerId();
        $this->installation['item_path'] = public_url('items/show/');

        $serverUrlHelper = new Zend_View_Helper_ServerUrl;
        $this->installation['server_url'] = $serverUrlHelper->serverUrl();
    }

    public function convertResponsesToMessageString($responses)
    {
        $messageString = '';

        foreach ($responses as $response)
        {
            if (isset($response['error']))
            {
                $error = $response['error'];
                $reason = isset($error['reason']) ? $error['reason'] : '';
                $causedBy = isset($error['caused_by']['reason']) ? $error['caused_by']['reason'] : '';
                $message = $response['_id'] . ' : ' . $error['type'] . ' - ' . $reason . ' - ' . $causedBy;
                $messageString .= $message .= '<br/>';
            }
        }

        return $messageString;
    }

    public function createDocumentsForAllItems($filename, $limit = 0)
    {
        $this->cacheInstallationParameters();

        // Get all the items for this installation.
        $items = $this->fetchAllItems();

        // Get the entire Files table once so that each document won't have to do a SQL query to get its item's files.
        $files = $this->fetchAllFiles();

        // The limit is only used during development so that we don't always have
        // to index all the items. It serves no purpose in a production environment
        $itemsCount = $limit == 0 ? count($items) : $limit;

        // Start the JSON array of document objects.
        $this->writeToExportFile($filename, '[');

        for ($index = 0; $index < $itemsCount; $index++)
        {
            $itemId = $items[$index]->id;

            $itemFiles = array();
            if (isset($files[$itemId]))
            {
                $itemFiles = $files[$itemId];
            }

            // Create a document for the item.
            $document = $this->createElasticsearchDocumentFromItem($items[$index], $itemFiles);

            // Write the document as an object to the JSON array. Separate each object by a comma.
            $json = json_encode($document);
            $separator = $index > 0 ? ',' : '';
            $this->writeToExportFile($filename, $separator . $json);

            // Let PHP know that it can garbage-collect these objects.
            unset($items[$index]);
            if (isset($files[$itemId]))
            {
                unset($files[$itemId]);
            }
            unset($document);
        }

        // End the JSON array.
        $this->writeToExportFile($filename, ']');
    }

    public function createElasticsearchDocumentFromItem($item, $files)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($item);
        $document = new AvantElasticsearchDocument($documentId);

        // Provide data that has been cached here by the index builder to improve performance.
        $document->setInstallationParameters($this->installation);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($item, $files);

        return $document;
    }

    public function deleteIndex()
    {
        $params = ['index' => $this->docIndex];
        $avantElasticsearchClient = new AvantElasticsearchClient();
        $response = $avantElasticsearchClient->deleteIndex($params);
        return $response;
    }

    public function deleteItem($item)
    {
        $documentId = (new AvantElasticsearch())->getDocumentIdForItem($item);
        $document = new AvantElasticsearchDocument($documentId);
        $response = $document->deleteDocumentFromIndex();
        return $response;
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

    public function getBulkParams(array $documents, $offset, $length)
    {
        if ($offset < 0 || $length < 0)
        {
            throw new Exception("offset less than zero");
        }

        if (isset($length))
        {
            if ($offset + $length > count($documents))
            {
                $end = count($documents);
            }
            else
            {
                $end = $offset + $length;
            }
        }
        else
        {
            $end = count($documents);
        }

        $params = ['body' => []];
        for ($i = $offset; $i < $end; $i++)
        {
            $document = $documents[$i];
            $actionsAndMetadata = [
                'index' => [
                    '_index' => $document->index,
                    '_type'  => $document->type,
                ]
            ];
            if(isset($document->id))
            {
                $actionsAndMetadata['index']['_id'] = $document->id;
            }
            $params['body'][] = $actionsAndMetadata;
            $params['body'][] = isset($document->body) ? $document->body : '';
        }
        return $params;
    }

    public function indexItem($item)
    {
        $document = $this->createElasticsearchDocumentFromItem($item, $item->Files);

        // Add the document to the index.
        $response = $document->addDocumentToIndex();
        return $response;
    }

    protected function preformBulkIndexExport($filename, $limit = 0)
    {
        if (file_exists($filename))
        {
            unlink($filename);
        }

        $this->createDocumentsForAllItems($filename, $limit);
    }

    protected function performBulkIndexImport($filename)
    {

        $batchSize = 500;
        $responses = array();

        $docs = array();
        if (file_exists($filename))
        {
            $docs = file_get_contents($filename);
            $docs = json_decode($docs, false);
        }

        $docsCount = count($docs);

        $avantelasticSearchMappings = new AvantElasticsearchMappings();

        $params = [
            'index' => 'omeka',
            'body' => ['mappings' => $avantelasticSearchMappings->constructElasticsearchMapping()]
        ];

        $avantElasticsearchClient = new AvantElasticsearchClient();
        $response = $avantElasticsearchClient->createIndex($params);

        for ($offset = 0; $offset < $docsCount; $offset += $batchSize)
        {
            $params = $this->getBulkParams($docs, $offset, $batchSize);
            $response = $avantElasticsearchClient->indexMultipleDocuments($params);

            if ($response['errors'] == true)
            {
                $responses[] = $response["items"][0]["index"];
            }
        }

        return $responses;
    }

    public function performBulkIndex($export, $limit)
    {
        $responses = array();
        $filename = ElasticsearchConfig::getOptionValueForExportFile();

        if ($export)
        {
            $this->preformBulkIndexExport($filename, $limit);
        }
        else
        {
            $this->deleteIndex();
            $responses = $this->performBulkIndexImport($filename);
        }

        return $responses;
    }

    public function indexAll($export, $limit)
    {
        $responses = $this->performBulkIndex($export, $limit);

        return $responses;
    }

    protected function writeToExportFile($filename, $text)
    {
        $this->json .= $text;
        if ($text == ']')
        {
            //file_put_contents($filename, $text, FILE_APPEND);
            file_put_contents($filename, $this->json);
        }
    }
}