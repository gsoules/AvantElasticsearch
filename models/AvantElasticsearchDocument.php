<?php
class AvantElasticsearchDocument extends AvantElasticsearch
{
    // These need to be public so that objects of this class can be JSON encoded/decoded.
    public $id;
    public $index;
    public $type;
    public $body = [];

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

    protected function constructAddressElement($elementName, $elasticsearchFieldName, $texts, &$elementData)
    {
        if ($elementName == 'Address')
        {
            $text = $texts[0];

            if (preg_match('/([^a-zA-Z]+)?(.*)/', $text, $matches))
            {
                // Try to get a number from the number portion. If there is none, intval return 0 which is good for sorting.
                $numberMatch = $matches[1];
                $number = intval($numberMatch);

                $elementData[$elasticsearchFieldName . '-number'] = sprintf('%010d', $number);
                $elementData[$elasticsearchFieldName . '-street'] = $matches[2];
            }
        }
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

    protected function constructElementTextsString($texts, $elementName, $isHtmlElement)
    {
        // Get the element's text and catentate them into a single string separate by EOL breaks.
        // Though Elasticsearch supports mulitple field values stored in arrays, it does not support
        // sorting based on the first value as is required by AvantSearch when a user sorts by column.
        // By catenating the values, sorting will work as desired.

        $elementTexts = $this->catentateElementTexts($texts);

        // Change Description content to plain text for two reasons:
        // 1. Prevent searches from finding HTML tag names like span or strong.
        // 2. Allow proper hit highlighting in search results with showing highlighted HTML tags.
        if ($elementName == 'Description' && $isHtmlElement)
        {
            $elementTexts = strip_tags($texts[0]);
        }

        return $elementTexts;
    }

    protected function constructHierarchy($elementName, $elasticsearchFieldName, $texts, &$elementData)
    {
        if ($elementName == 'Place' || $elementName == 'Type' || $elementName == 'Subject')
        {
            $text = $texts[0];

            // Find the last comma.
            $index = strrpos($text, ',', -1);
            if ($index !== false)
            {
                // Filter out the ancestry to leave just the leaf text.
                $text = trim(substr($text, $index + 1));
            }
            $elementData[$elasticsearchFieldName . '-sort'] = $text;
        }
    }

    protected function constructHtmlElement($elementName, $elasticsearchFieldName, $item, &$htmlFields)
    {
        // Determine if this element is from a field that allows HTML and use HTML.
        // If so, add the element's name to a list of fields that contain HTML content.
        // This will be needed so that search results will show the content properly and not as raw HTML.

        $elementSetName = ItemMetadata::getElementSetNameForElementName($elementName);
        $isHtmlElement = $item->getElementTexts($elementSetName, $elementName)[0]->isHtml();
        if ($isHtmlElement)
        {
            $htmlFields[] = $elasticsearchFieldName;
        }

        return $isHtmlElement;
    }

    protected function constructIntegerElement($elementName, $elasticsearchFieldName, $elementTexts, &$elementData)
    {
        $integerSortElements = SearchConfig::getOptionDataForIntegerSorting();
        if (in_array($elementName, $integerSortElements))
        {
            $elementData[$elasticsearchFieldName . '-sort'] = sprintf('%010d', $elementTexts);
        }
    }

    protected function constructTags($item)
    {
        $tags = [];
        foreach ($item->getTags() as $tag)
        {
            $tags[] = $tag->name;
        }
        $this->setField('tags', $tags);
    }

    protected function constructTitle($texts)
    {
        $title = $this->catentateElementTexts($texts);
        if (strlen($title) == 0) {
            $title = __('Untitled');
        }
        return $title;
    }

    protected function loadFields($item, $texts)
    {
        $title = $this->constructTitle($texts);
        $itemPath = public_url('items/show/' . metadata('item', 'id'));
        $serverUrlHelper = new Zend_View_Helper_ServerUrl;
        $serverUrl = $serverUrlHelper->serverUrl();
        $itemPublicUrl = $serverUrl . $itemPath;
        $itemImageThumbUrl = ItemPreview::getImageUrl($item, false, true);
        $itemImageOriginalUrl = ItemPreview::getImageUrl($item, false, false);
        $itemFiles = $item->Files;
        $fileCount = count($itemFiles);
        $owner = get_option('site_title');
        $ownerId = $this->getOwnerId();

        $this->setFields([
            'itemid' => $item->id,
            'ownerid' => $ownerId,
            'owner' => $owner,
            'title' => $title,
            'public' => $item->public,
            'url' => $itemPublicUrl,
            'thumb' => $itemImageThumbUrl,
            'image' => $itemImageOriginalUrl,
            'files' => $fileCount
        ]);
    }

    public function loadItemContent($item)
    {
        set_current_record('Item', $item);
        $texts = ItemMetadata::getAllElementTextsForElementName($item, 'Title');
        $this->loadFields($item, $texts);

        try
        {
            $elementData = [];
            $facets = [];
            $htmlFields = [];

            $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
            $elementTexts = $item->getAllElementTexts();
            $avantElasticsearch = new AvantElasticsearch();

            foreach ($elementTexts as $elementText)
            {
                $element = $item->getElementById($elementText->element_id);

                if (array_key_exists($element->id, $privateElementsData))
                {
                    // Skip private elements.
                    continue;
                }

                // Get the element name and create the corresponding Elasticsearch field name.
                $elementName = $element->name;
                $elasticsearchFieldName = $avantElasticsearch->convertElementNameToElasticsearchFieldName($elementName);

                // Get the element's text values as a single string with the values catenated.
                $isHtmlElement = $this->constructHtmlElement($elementName, $elasticsearchFieldName, $item, $htmlFields);
                $texts = ItemMetadata::getAllElementTextsForElementName($item, $elementName);
                $elementTextsString = $this->constructElementTextsString($texts, $elementName, $isHtmlElement);

                // Save the element's text.
                $elementData[$elasticsearchFieldName] = $elementTextsString;

                // Process special cases.
                $this->constructIntegerElement($elementName, $elasticsearchFieldName, $elementTextsString, $elementData);
                $this->constructHierarchy($elementName, $elasticsearchFieldName, $texts, $elementData);
                $this->constructAddressElement($elementName, $elasticsearchFieldName, $texts, $elementData);

                $avantElasticsearchFacets = new AvantElasticsearchFacets();
                $avantElasticsearchFacets->constructFacets($elementName, $elasticsearchFieldName, $texts, $facets);
            }

            $this->setField('element', $elementData);
            $this->setField('facets', $facets);
            $this->setField('html', $htmlFields);
        }
        catch (Omeka_Record_Exception $e)
        {
            return null;
        }

        $this->constructTags($item);
    }

    public function deleteDocumentFromIndex()
    {
        $documentParmas = $this->constructDocumentParameters();
        $avantElasticsearchClient = new AvantElasticsearchClient();
        $response = $avantElasticsearchClient->deleteDocument($documentParmas);
        return $response;
    }

    public function setField($key, $value)
    {
        $this->body[$key] = $value;
    }

    public function setFields(array $params = array())
    {
        $this->body = array_merge($this->body, $params);
    }
}