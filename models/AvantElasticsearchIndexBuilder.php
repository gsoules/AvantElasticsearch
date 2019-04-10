<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
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

    public function createDocumentsForAllItems($limit = 0)
    {
        $mem1 = memory_get_usage();
        $documents = array();
        $items = $this->fetchAllItems();
        $itemsCount = count($items);

        if ($itemsCount > 0)
        {
            $limit = $limit == 0 ? $itemsCount : $limit;

            for ($index = 0; $index < $limit; $index++)
            {
                $item = $items[$index];

                if ($item->public == 0)
                {
                    // Skip private items.
                    continue;
                }
                $mem2 = memory_get_usage();
                $documents[] = $this->createElasticsearchDocumentFromItem($item);
                release_object($item);
                $mem3 = memory_get_usage();
            }
        }
        $mem4 = memory_get_usage();
        return $documents;
    }

    public function createElasticsearchDocumentFromItem($item)
    {
        $documentId = $this->getDocumentIdForItem($item);
        $document = new AvantElasticsearchDocument($documentId);
        $document->loadItemContent($item);
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
        $documents = $this->createDocumentsForAllItems($limit);
        $formattedData = json_encode($documents);
        $handle = fopen($filename, 'w+');
        fwrite($handle, $formattedData);
        fclose($handle);
        return array();
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
        $filename = ElasticsearchConfig::getOptionValueForExportFile();

        if ($export)
        {
            $responses = $this->preformBulkIndexExport($filename, $limit);
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
}