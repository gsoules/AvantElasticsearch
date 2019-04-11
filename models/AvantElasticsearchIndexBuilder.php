<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
    private $elementsUsedByThisInstallation = array();
    private $integerSortElements = array();

    public function __construct()
    {
        parent::__construct();
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
        // Perform expensive SQL queries to get and cache data that every document will use during its creation.
        // Doing this significantly improves performance when creating thousands of documents.
        // When enhancing this code, be very careful about using method calls that depend on SQL queries, and
        // whenever possible, make the calls just once and cache the values here.
        $this->integerSortElements = SearchConfig::getOptionDataForIntegerSorting();
        $this->elementsUsedByThisInstallation = $this->getElementsUsedByThisInstallation();

        // Get all the items for this installation.
        $items = $this->fetchAllItems();
        $itemsCount = count($items);


        if ($itemsCount > 0)
        {
            $this->writeToJsonFile($filename, '[', 0, false);

            $limit = $limit == 0 ? $itemsCount : $limit;

            for ($index = 0; $index < $limit; $index++)
            {
                $document = $this->createElasticsearchDocumentFromItem($items[$index]);

                $this->writeToJsonFile($filename, $document, $index, true);

                // Let PHP know that it can garbage-collect these objects.
                unset($items[$index]);
                unset($document);
            }

            $this->writeToJsonFile($filename, ']', 0, false);
        }
    }

    public function createElasticsearchDocumentFromItem($item)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($item);
        $document = new AvantElasticsearchDocument($documentId);

        // Set data that has been cached here by the index builder so that the document can access it.
        $document->setElementsUsedByThisInstallation($this->elementsUsedByThisInstallation);
        $document->setIntegerSortElements($this->integerSortElements);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($item);

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

    protected function fetchAllItems($getOnlyPublic = true)
    {
        try
        {
            $db = get_db();
            $table = $db->getTable('Item');
            $select = $table->getSelect();
            if ($getOnlyPublic)
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
        $document = $this->createElasticsearchDocumentFromItem($item);

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

    public function writeToJsonFile($filename, $document, $index, $jsonEncode)
    {
        $text = $jsonEncode ? json_encode($document) : $document;
        $separator = $index > 0 ? ',' : '';
        file_put_contents($filename, "{$separator}{$text}", FILE_APPEND);
    }
}