<?php

class AvantElasticsearchIndexBuilder extends AvantElasticsearch
{
    private $installation;

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

        $this->installation['integer_sort_fields'] = array_map('strtolower', SearchConfig::getOptionDataForIntegerSorting());
        $this->installation['installation_elements'] = $this->getElementsUsedByThisInstallation();
        $this->installation['contributor'] = ElasticsearchConfig::getOptionValueForContributor();
        $this->installation['contributorid'] = ElasticsearchConfig::getOptionValueForContributorId();
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

    public function createElasticsearchDocumentFromItem($item, $itemFieldTexts, $files)
    {
        // Create a new document.
        $documentId = $this->getDocumentIdForItem($item);
        $document = new AvantElasticsearchDocument($documentId);

        // Provide the document with data that has been cached here by the index builder to improve performance.
        $document->setInstallationParameters($this->installation);

        // Provide the document with access to facet definitions.
        $avantElasticsearchFacets = new AvantElasticsearchFacets();
        $document->setAvantElasticsearchFacets($avantElasticsearchFacets);

       // Populate the document fields with the item's element values;
        $document->copyItemElementValuesToDocument($item, $itemFieldTexts, $files);

        return $document;
    }

    public function deleteIndex()
    {
        $params = ['index' => $this->documentIndexName];
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

    public function getindexDataFilename()
    {
        $adapterOptions = Zend_Registry::get('storage')->getAdapter()->getOptions();
        $path = $adapterOptions['localDir'];
        $indexDataId = ElasticsearchConfig::getOptionValueForExportFile();
        $filename = $path . DIRECTORY_SEPARATOR . 'elasticsearch' . DIRECTORY_SEPARATOR . $indexDataId . '.json';
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

    public function indexSingleItem($item)
    {
        $this->cacheInstallationParameters();

        $itemFieldTexts = $this->getItemFieldTexts($item);
        $files = $item->Files;
        $document = $this->createElasticsearchDocumentFromItem($item, $itemFieldTexts, $files);

        // Add the document to the index.
        $response = $document->addDocumentToIndex();
        return $response;
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

        // The limit is only used during development so that we don't always have
        // to index all the items. It serves no purpose in a production environment
        $itemsCount = $limit == 0 ? count($items) : $limit;

        for ($index = 0; $index < $itemsCount; $index++)
        {
            $itemId = $items[$index]->id;

            $itemFiles = array();
            if (isset($files[$itemId]))
            {
                $itemFiles = $files[$itemId];
            }

            // Create a document for the item.
            $document = $this->createElasticsearchDocumentFromItem($items[$index], $fieldTextsForAllItems[$itemId], $itemFiles);

            // Write the document as an object to the JSON array, separating each object by a comma.
            $separator = $index > 0 ? ',' : '';
            $json .= $separator . json_encode($document);

            // Let PHP know that it can garbage-collect these objects.
            unset($items[$index]);
            if (isset($files[$itemId]))
            {
                unset($files[$itemId]);
            }
            unset($document);
        }

        file_put_contents($filename, "[$json]");

        return array();
    }

    public function performBulkIndexImport($filename, $deleteExistingIndex)
    {
        if ($deleteExistingIndex)
        {
            $this->deleteIndex();
        }

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
}