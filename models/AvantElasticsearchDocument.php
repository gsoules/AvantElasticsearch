<?php
class AvantElasticsearchDocument extends AvantElasticsearch
{
    // These need to be public so that objects of this class can be JSON encoded/decoded.
    public $id;
    public $index;
    public $type;
    public $body = [];

    /* @var $avantElasticsearchFacets AvantElasticsearchFacets */
    protected $avantElasticsearchFacets;
    protected $facetDefinitions;

    // Cached data.
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
            'index' => $this->documentIndexName,
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

    protected function constructTags($item)
    {
        // TO-DO: Optimize indexing of tags to reduce export time.
        // Replace this SQL call loop with a single fetch in preformBulkIndexExport as is done for items, files,
        // and element texts. But this can wait until tags are being used.
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

        $elementData = [];
        $sortData = [];
        $facetData = [];
        $htmlFields = [];

        $itemHasDate = false;
        $itemHasIdentifier = false;
        $titleString = '';
        $titleFieldTexts = null;
        $itemTypeIsReference = false;
        $itemSubjectIsPeople = false;

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            $elasticsearchFieldName = $this->installation['installation_elements'][$elementId];

            foreach ($fieldTexts as $key => $fieldText)
            {
                if ($fieldText['html'] == 1)
                {
                    // Change any HTML content to plain text so that Elasticsearch won't get hits on HTML tags. For
                    // example, if the query contained 'strong' we don't want the search to find the <strong> tag.
                    $fieldTexts[$key]['text'] = strip_tags($fieldText['text']);
                }
            }

            // Get the element's text and catentate them into a single string separate by EOL breaks.
            // Though Elasticsearch supports mulitple field values stored in arrays, it does not support
            // sorting based on the first value as is required by AvantSearch when a user sorts by column.
            // By catenating the values, sorting will work as desired.
            $fieldTextsString = $this->catentateElementTexts($fieldTexts);

            // Identify which if any of this element's text values contain HTML.
            $this->createHtmlData($elasticsearchFieldName, $fieldTexts, $htmlFields);

            if ($elasticsearchFieldName == 'title')
            {
                $titleString = $fieldTextsString;
                if (strlen($titleString) == 0)
                {
                    $titleString = __('Untitled');
                }
                $titleFieldTexts = $fieldTexts;
            }

            if ($elasticsearchFieldName == 'identifier')
            {
                $itemHasIdentifier = true;
            }

            if ($elasticsearchFieldName == 'date')
            {
                $itemHasDate = true;
            }

            if ($elasticsearchFieldName == 'type')
            {
                // TO-DO: Make this logic generic so it doesn't depend on knowledge of specific type and subject values.
                $itemTypeIsReference = $fieldTextsString == 'Reference';
            }

            if ($elasticsearchFieldName == 'subject')
            {
                $itemSubjectIsPeople = strpos($fieldTextsString, 'People') !== false;
            }

            // Save the element's text.
            $elementData[$elasticsearchFieldName] = $fieldTextsString;

            // Add information to the document about special elements.
            $this->createIntegerElementSortData($elasticsearchFieldName, $fieldTextsString, $sortData);
            $this->createHierarchyElementSortData($elasticsearchFieldName, $fieldTexts, $sortData);
            $this->createAddressElementSortData($elasticsearchFieldName, $fieldTexts, $sortData);
            $this->createElementFacetData($elasticsearchFieldName, $fieldTexts, $facetData);
        }

        // Add facet data for this installation's as the contributor.
        $contributorFieldTexts = $this->createFieldTexts($this->installation['contributor']);
        $this->createElementFacetData('contributor', $contributorFieldTexts, $facetData);

        if (!$itemHasIdentifier)
        {
            // This installation does not use the Identifier element because it has an Identifier Alias
            // configured in AvantCommon. Get the alias value and use it as the identifier field value.
            $aliasElementId = CommonConfig::getOptionDataForIdentifierAlias();
            $aliasText = $itemFieldTexts[$aliasElementId][0]['text'];
            $elementData['identifier'] = $aliasText;
        }

        if (!$itemHasDate)
        {
            // Create an empty field-text to represent date unknown. Wrap it in a field-texts array.
            $emptyDateFieldTexts = $this->createFieldTexts('');
            $this->createElementFacetData('date', $emptyDateFieldTexts, $facetData);
        }

        if (!empty($titleFieldTexts))
        {
            $avantLogicSuggest = new AvantElasticsearchSuggest();
            $itemTitleIsPerson = $itemTypeIsReference && $itemSubjectIsPeople;
            $suggestionData = $avantLogicSuggest->CreateSuggestionsDataForTitle($titleFieldTexts, $itemTypeIsReference, $itemTitleIsPerson);
            $this->body['suggestions'] = $suggestionData;
        }

        $this->createElementTagData($item, $facetData);

        if (!empty($htmlFields))
        {
            $this->setField('html', $htmlFields);
        }

        $this->setField('element', $elementData);
        $this->setField('sort', $sortData);
        $this->setField('facet', $facetData);

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
            'contributorid' => $this->installation['contributorid'],
            'contributor' => $this->installation['contributor'],
            'title' => $titleString,
            'public' => (bool)$item->public,
            'url' => $itemPublicUrl,
            'thumb' => $itemImageThumbUrl,
            'image' => $itemImageOriginalUrl,
            'files' => count($this->itemFiles)
        ]);
    }

    protected function createAddressElementSortData($elasticsearchFieldName, $fieldText, &$sortData)
    {
        if ($elasticsearchFieldName == 'address')
        {
            $text = $fieldText[0]['text'];

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

    protected function createElementFacetData($elasticsearchFieldName, $fieldTexts, &$facets)
    {
        if (!isset($this->facetDefinitions[$elasticsearchFieldName]))
        {
            // This element is not used as a facet.
            return;
        }

        $facetValuesForElement = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTexts);

        // Get each of the element's values either as text, or as root/leaf pairs.
        foreach ($facetValuesForElement as $facetValue)
        {
            if (is_array($facetValue))
            {
                // When the value is an array, the components are always root and leaf.
                $facets["$elasticsearchFieldName.root"][] = $facetValue['root'];
                $facets["$elasticsearchFieldName.leaf"][] = $facetValue['leaf'];
            }
            else
            {
                $facets[$elasticsearchFieldName][] = $facetValue;
            }
        }
    }

    protected function createElementTagData($item, &$facets)
    {
        if (!$this->facetDefinitions['tag']['not_used'])
        {
            $tags = $this->constructTags($item);
            if (!empty($tags))
            {
                $facets['tag'] = $tags;
                $this->setField('tags', $tags);
            }
        }
    }

    protected function createHierarchyElementSortData($elasticsearchFieldName, $fieldTexts, &$sortData)
    {
        if (!isset($this->facetDefinitions[$elasticsearchFieldName]))
        {
            // This element is not used as facet. Only hierarchy facets need hierarchy sort data.
            return;
        }

        if ($this->facetDefinitions[$elasticsearchFieldName]['is_hierarchy'])
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

    protected function createHtmlData($elasticsearchFieldName, $fieldTexts, &$htmlFields)
    {
        // Determine if this element contains and HTML texts. If so, record the field name
        // followed by a comma-separated list of the indices of containing HTML. For example,
        // if the Creator element has three values and the first (index 0) and last (index 2)
        // contain HTML, create the value "creator,0,2".

        $index = 0;
        $htmlTextIndices = '';

        foreach ($fieldTexts as $fieldText)
        {
            if ($fieldText['html'] == 1)
            {
                $htmlTextIndices .= ",$index";
            }
            $index++;
        }

        if (!empty($htmlTextIndices))
        {
            $htmlFields[] = $elasticsearchFieldName . $htmlTextIndices;
        }
    }

    protected function createIntegerElementSortData($elasticsearchFieldName, $textString, &$sortData)
    {
        if (in_array($elasticsearchFieldName, $this->installation['integer_sort_fields']))
        {
            // Pad the beginning of the value with leading zeros so that integers can be sorted correctly as text.
            $sortData[$elasticsearchFieldName] = sprintf('%010d', $textString);
        }
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

    public function setAvantElasticsearchFacets($avantElasticsearchFacets)
    {
        $this->avantElasticsearchFacets = $avantElasticsearchFacets;
        $this->facetDefinitions = $this->avantElasticsearchFacets->getFacetDefinitions();
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