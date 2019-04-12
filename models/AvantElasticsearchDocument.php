<?php
class AvantElasticsearchDocument extends AvantElasticsearch
{
    // These need to be public so that objects of this class can be JSON encoded/decoded.
    public $id;
    public $index;
    public $type;
    public $body = [];

    private $installation;
    private $itemFiles;

    public function __construct($documentId)
    {
        parent::__construct();

        $this->id = $documentId;
        $this->index = $this->getElasticsearchIndexName();
        $this->type = $this->getDocumentMappingType();
    }

    public function addDocumentToIndex()
    {
        $documentParmas = $this->constructDocumentParameters();
        $avantElasticsearchClient = new AvantElasticsearchClient();
        $response = $avantElasticsearchClient->indexDocument($documentParmas);
        return $response;
    }

    public function constructDocumentParameters()
    {
        $params = [
            'index' => $this->docIndex,
            'type' => $this->type,
        ];

        if (isset($this->id))
        {
            $params['id'] = $this->id;
        }

        if (!empty($this->body))
        {
            $params['body'] = $this->body;
        }

        return $params;
    }

    protected function constructHtmlElement($elasticsearchFieldName, $text, &$htmlFields)
    {
        // Determine if this element's text contains HTML. If so, add the element's name to the item's HTML list
        // So that the search results display logic will know to show the content properly and not as raw HTML.
        // We used to do this with Omeka calls to query if the element allowed HTML, but the underlying SQL queries
        // were too expensive and slowed down document processing. Now we simply see if the text changes after
        // stripping any HTML tags.

        $strippedText = strip_tags($text);
        $isHtmlElement = $strippedText != $text;

        if ($isHtmlElement)
        {
            $htmlFields[] = $elasticsearchFieldName;
        }
        return $isHtmlElement;
    }

    protected function createAddressElementSortData($elementName, $elasticsearchFieldName, $texts, &$sortData)
    {
        if ($elementName == 'Address')
        {
            $text = $texts[0];

            if (preg_match('/([^a-zA-Z]+)?(.*)/', $text, $matches))
            {
                // Try to get a number from the number portion. If there is none, intval return 0 which is good for sorting.
                $numberMatch = $matches[1];
                $number = intval($numberMatch);

                // Pad the beginning of the number with leading zeros so that it can be sorted correctly as text.
                $sortData[$elasticsearchFieldName . '-number'] = sprintf('%010d', $number);

                $sortData[$elasticsearchFieldName . '-street'] = $matches[2];
            }
        }
    }

    protected function createHierarchyElementSortData($elementName, $elasticsearchFieldName, $fieldTexts, &$sortData)
    {
        if ($elementName == 'Place' || $elementName == 'Type' || $elementName == 'Subject')
        {
            // Get only the first value for this element since that's all that's used for sorting purposes.
            $text = $fieldTexts[0]['text'];

            // Find the last comma.
            $index = strrpos($text, ',', -1);
            if ($index !== false)
            {
                // Filter out the ancestry to leave just the leaf text.
                $text = trim(substr($text, $index + 1));
            }
            $sortData[$elasticsearchFieldName] = $text;
        }
    }

    protected function createIntegerElementSortData($elementName, $elasticsearchFieldName, $textString, &$sortData)
    {
        if (in_array($elementName, $this->installation['integer_sort_elements']))
        {
            // Pad the beginning of the value with leading zeros so that integers can be sorted correctly as text.
            $sortData[$elasticsearchFieldName] = sprintf('%010d', $textString);
        }
    }

    protected function constructTags($item)
    {
        $tags = array();
        foreach ($item->getTags() as $tag)
        {
            $tags[] = $tag->name;
        }
        return $tags;
    }

    public function copyItemElementValuesToDocument($item, $itemFieldTexts, $files)
    {
        $this->itemFiles = $files;

        $avantElasticsearchFacets = new AvantElasticsearchFacets();

        $elementData = [];
        $sortData = [];
        $facets = [];
        $htmlFields = [];

        $avantElasticsearch = new AvantElasticsearch();

        $hasDateElement = false;
        $titleString = '';

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            // Get the element name and create the corresponding Elasticsearch field name.
            $elementName = $this->installation['installation_elements'][$elementId];

            $elasticsearchFieldName = $avantElasticsearch->convertElementNameToElasticsearchFieldName($elementName);
            if ($elementName == 'Date')
            {
                $hasDateElement = true;
            }

            // Get the element's text and catentate them into a single string separate by EOL breaks.
            // Though Elasticsearch supports mulitple field values stored in arrays, it does not support
            // sorting based on the first value as is required by AvantSearch when a user sorts by column.
            // By catenating the values, sorting will work as desired.
            $fieldTextsString = $this->catentateElementTexts($fieldTexts);

            // Determine if the element's text contains HTML and if so, add the element to the item's HTML list.
            $isHtmlText = $fieldTexts['html'] == 1;
            $isHtmlElement = $this->constructHtmlElement($elasticsearchFieldName, $fieldTextsString, $htmlFields);

            // Change Description content to plain text for two reasons:
            // 1. Prevent searches from finding HTML tag names like span or strong.
            // 2. Allow proper hit highlighting in search results with showing highlighted HTML tags.
            if ($elementName == 'Description' && $isHtmlElement)
            {
                $fieldTextsString = strip_tags($fieldTextsString);
            }

            if ($elementName == 'Title')
            {
                $titleString = $fieldTextsString;
                if (strlen($titleString) == 0) {
                    $titleString = __('Untitled');
                }
            }

            // Save the element's text.
            $elementData[$elasticsearchFieldName] = $fieldTextsString;

            // Process special cases.
            $this->createIntegerElementSortData($elementName, $elasticsearchFieldName, $fieldTextsString, $sortData);
            $this->createHierarchyElementSortData($elementName, $elasticsearchFieldName, $fieldTexts, $sortData);
            $this->createAddressElementSortData($elementName, $elasticsearchFieldName, $fieldTexts, $sortData);

            $avantElasticsearchFacets->getFacetValue($elementName, $elasticsearchFieldName, $fieldTexts, $facets);
        }

        if (!$hasDateElement)
        {
            $avantElasticsearchFacets->getFacetValue('Date', 'date', array(''), $facets);
        }

        $tags = $this->constructTags($item);
        $facets['tag'] = $tags;

        $this->setField('element', $elementData);
        $this->setField('sort', $sortData);
        $this->setField('facet', $facets);
        $this->setField('html', $htmlFields);
        $this->setField('tags', $tags);

        $this->copyItemAttributesToDocument($item, $titleString);
    }

    protected function copyItemAttributesToDocument($item, $titleString)
    {
        $itemPath = $this->installation['item_path'] . $item->id;
        $serverUrl = $this->installation['server_url'];
        $itemPublicUrl = $serverUrl . $itemPath;

        $itemImageThumbUrl = $this->getImageUrl($item, true);
        $itemImageOriginalUrl = $this->getImageUrl($item, false);

        $this->setFields([
            'itemid' => (int)$item->id,
            'ownerid' => $this->installation['ownerid'],
            'owner' => $this->installation['owner'],
            'title' => $titleString,
            'public' => (bool)$item->public,
            'url' => $itemPublicUrl,
            'thumb' => $itemImageThumbUrl,
            'image' => $itemImageOriginalUrl,
            'files' => count($this->itemFiles)
        ]);
    }

    protected function getImageUrl($item, $thumbnail)
    {
        $itemImageUrl = $this->getItemFileUrl($thumbnail);

        if (empty($itemImageUrl))
        {
            $coverImageIdentifier = ItemPreview::getCoverImageIdentifier($item->id);
            if (!empty($coverImageIdentifier))
            {
                $coverImageItem = ItemMetadata::getItemFromIdentifier($coverImageIdentifier);
                $itemImageUrl = empty($coverImageItem) ? '' : ItemPreview::getItemFileUrl($coverImageItem, $thumbnail);
            }
        }

        return $itemImageUrl;
    }

    protected function getItemFileUrl($thumbnail)
    {
        // This method is faster than ItemPreview::getItemFileUrl because it uses the cached $this->itemFiles
        // which allows this method to get called multiple times without having to fetch the files array each time.
        // This improvement saves a significant amount of time when indexing all items.

        $url = '';
        $file = empty($this->itemFiles) ? null : $this->itemFiles[0];
        if (!empty($file) && $file->hasThumbnail())
        {
            $url = $file->getWebPath($thumbnail ? 'thumbnail' : 'original');
            if (strlen($url) > 4 && strpos(strtolower($url), '.jpg', strlen($url) - 4) === false)
            {
                // The original image is not a jpg (it's probably a pdf) so return its derivative image instead.
                $url = $file->getWebPath($thumbnail ? 'thumbnail' : 'fullsize');
            }
        }
        return $url;
    }

    public function deleteDocumentFromIndex()
    {
        $documentParmas = $this->constructDocumentParameters();
        $avantElasticsearchClient = new AvantElasticsearchClient();
        $response = $avantElasticsearchClient->deleteDocument($documentParmas);
        return $response;
    }

    public function setElementsUsedByThisInstallation($elements)
    {
        $this->elementsUsedByThisInstallation = $elements;
    }

    public function setField($key, $value)
    {
        $this->body[$key] = $value;
    }

    public function setFields(array $params = array())
    {
        $this->body = array_merge($this->body, $params);
    }

    public function setInstallationParameters($installation)
    {
        $this->installation = $installation;
    }
}