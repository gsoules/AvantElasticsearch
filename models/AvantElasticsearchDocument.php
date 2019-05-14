<?php
class AvantElasticsearchDocument extends AvantElasticsearch
{
    // These need to be public so that objects of this class can be JSON encoded/decoded.
    public $id;
    public $index;
    public $type;
    public $body = [];

    // Cached data.
    private $installation;
    private $itemFiles;

    /* @var $avantElasticsearchFacets AvantElasticsearchFacets */
    protected $avantElasticsearchFacets;
    protected $facetDefinitions;
    protected $itemHasDate = false;
    protected $itemHasIdentifier = false;
    protected $itemTypeIsReference = false;
    protected $itemSubjectIsPeople = false;
    protected $titleString = '';
    protected $titleFieldTexts = null;

    // Arrays for collecting multiple values.
    protected $elementData = [];
    protected $sortData = [];
    protected $facetData = [];
    protected $htmlFields = [];

    public function __construct($indexName = '', $documentId = 0)
    {
        parent::__construct();

        $this->setIndexName($indexName);
        $this->id = $documentId;
        $this->index = $indexName;
        $this->type = $this->getDocumentMappingType();
    }

    protected function addItemDataToDocumentBody($itemId, $isPublic)
    {
        if (!empty($this->htmlFields))
            $this->setField('html-fields', $this->htmlFields);

        $urlData = $this->getItemUrlData($itemId);
        $this->setField('url', $urlData);

        $pdfData = $this->getItemPdfData();
        if (!empty($pdfData))
            $this->setField('pdf', $pdfData);

        $itemData = $this->getItemData($itemId, $this->titleString, $isPublic);
        $this->setField('item', $itemData);

        $this->setField('element', $this->elementData);
        $this->setField('sort', $this->sortData);
        $this->setField('facet', $this->facetData);
    }

    protected function catentateElementTexts($fieldTexts)
    {
        // Get the element's text and catentate them into a single string separate by EOL breaks.
        // Though Elasticsearch supports mulitple field values stored in arrays, it does not support
        // sorting based on the first value as is required by AvantSearch when a user sorts by column.
        // By catenating the values, sorting will work as desired.

        $catenatedText = '';
        foreach ($fieldTexts as $fieldText)
        {
            if (!empty($catenatedText))
            {
                $catenatedText .= PHP_EOL;
            }
            $catenatedText .= $fieldText['text'];
        }
        return $catenatedText;
    }

    public function copyItemElementValuesToDocument($itemId, $itemFieldTexts, $itemFiles, $isPublic)
    {
        $this->itemFiles = $itemFiles;

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            $this->createFieldDataForElement($elementId, $fieldTexts);
        }

        $this->createFacetDataForContributor();
        $this->createSpecialFieldsData($itemFieldTexts);
        $this->createSuggestionsData();
        $this->createTagData($itemId);

        $this->addItemDataToDocumentBody($itemId, $isPublic);
    }

    protected function createAddressElementSortData($elasticsearchFieldName, $fieldText)
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
                $this->sortData[$elasticsearchFieldName . '-number'] = sprintf('%010d', $number);

                $this->sortData[$elasticsearchFieldName . '-street'] = $matches[2];
            }
        }
    }

    protected function createFacetDataForField($elasticsearchFieldName, $fieldTexts)
    {
        if (!isset($this->facetDefinitions[$elasticsearchFieldName]))
        {
            // This field is not used as a facet.
            return;
        }

        $facetValuesForElement = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTexts);

        // Get each of the element's values either as text, or as root/leaf pairs.
        foreach ($facetValuesForElement as $facetValue)
        {
            if (is_array($facetValue))
            {
                // When the value is an array, the components are always root and leaf.
                // Add the root and leaf values to the facets array.
                $rootName = $facetValue['root'];
                $leafName = $facetValue['leaf'];
                $this->facetData["$elasticsearchFieldName.root"][] = $rootName;
                $this->facetData["$elasticsearchFieldName.leaf"][] = $leafName;

                // If the leaf has a grandchild, add the root and first child name to the facets array.
                // This will allow the user to use facets to filter by root, by first child, or by leaf.
                $rootAndFirstChild = $this->avantElasticsearchFacets->getRootAndFirstChildNameFromLeafName($leafName);
                if ($rootAndFirstChild != $leafName)
                {
                    $this->facetData["$elasticsearchFieldName.leaf"][] = $rootAndFirstChild;
                }
            }
            else
            {
                $this->facetData[$elasticsearchFieldName][] = $facetValue;
            }
        }
    }

    protected function createFieldDataForElement($elementId, $fieldTexts)
    {
        // Get the element's field name.
        $elasticsearchFieldName = $this->installation['installation_elements'][$elementId];

        // Strip any HTML tags from the field's text value(s).
        $this->removeHtmlTagsFromFieldText($fieldTexts);

        // Save the element's text value(s) as single string.
        $fieldTextsString = $this->catentateElementTexts($fieldTexts);
        $this->elementData[$elasticsearchFieldName] = $fieldTextsString;

        // Set flags to indicate if this field requires special handling.
        $this->setSpecialFieldFlags($elasticsearchFieldName, $fieldTextsString, $fieldTexts);

        // Create the various kinds of data associated with this field.
        $this->createHtmlData($elasticsearchFieldName, $fieldTexts);
        $this->createIntegerElementSortData($elasticsearchFieldName, $fieldTextsString);
        $this->createHierarchyElementSortData($elasticsearchFieldName, $fieldTexts);
        $this->createAddressElementSortData($elasticsearchFieldName, $fieldTexts);
        $this->createFacetDataForField($elasticsearchFieldName, $fieldTexts);
    }

    protected function createHierarchyElementSortData($elasticsearchFieldName, $fieldTexts)
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
            $this->sortData[$elasticsearchFieldName] = $text;
        }
    }

    protected function createHtmlData($elasticsearchFieldName, $fieldTexts)
    {
        // Determine if this element contains any HTML texts. If so, record the field name
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
            $this->htmlFields[] = $elasticsearchFieldName . $htmlTextIndices;
        }
    }

    protected function createFacetDataForContributor()
    {
        // Add facet data for this installation as the contributor.
        $contributorFieldTexts = $this->createFieldTexts($this->installation['contributor']);
        $this->createFacetDataForField('contributor', $contributorFieldTexts);
    }

    protected function createIntegerElementSortData($elasticsearchFieldName, $textString)
    {
        if (in_array($elasticsearchFieldName, $this->installation['integer_sort_fields']))
        {
            // Pad the beginning of the value with leading zeros so that integers can be sorted correctly as text.
            $this->sortData[$elasticsearchFieldName] = sprintf('%010d', $textString);
        }
    }

    protected function createSpecialFieldsData($itemFieldTexts)
    {
        if (!$this->itemHasIdentifier)
        {
            // This installation does not use the Identifier element because it has an Identifier Alias
            // configured in AvantCommon. Get the alias value and use it as the identifier field value.
            $aliasElementId = CommonConfig::getOptionDataForIdentifierAlias();
            $aliasText = $itemFieldTexts[$aliasElementId][0]['text'];
            $this->elementData['identifier'] = $aliasText;
        }

        if (!$this->itemHasDate)
        {
            // Create an empty field-text to represent date unknown. Wrap it in a field-texts array.
            $emptyDateFieldTexts = $this->createFieldTexts('');
            $this->createFacetDataForField('date', $emptyDateFieldTexts);
        }
    }

    protected function createSuggestionsData()
    {
        if (!empty($this->titleFieldTexts))
        {
            $avantLogicSuggest = new AvantElasticsearchSuggest();
            $itemTitleIsPerson = $this->itemTypeIsReference && $this->itemSubjectIsPeople;

            // Get the suggestion data for the title text(s).
            $suggestionData = $avantLogicSuggest->createSuggestionsDataForTitle($this->titleFieldTexts, $this->itemTypeIsReference, $itemTitleIsPerson);

            // Make sure the array keys are consecutive. If there are holes which can occur
            // where duplicates were removed, Elasticsearch will complain during indexing.
            $inputs = array();
            foreach ($suggestionData['input'] as $input)
            {
                $inputs[] = $input;
            }
            $suggestionData['input'] = $inputs;

            $this->body['suggestions'] = $suggestionData;
        }
    }

    protected function createTagData($itemId)
    {
        if (!$this->facetDefinitions['tag']['not_used'])
        {
            // TO-DO: Optimize indexing of tags to reduce export time.
            // Replace the SQL calls to getItemFromId and getTags with a single fetch in preformBulkIndexExport
            // as is done for items, files, and element texts. But this can wait until tags are being used.
            $item = ItemMetadata::getItemFromId($itemId);
            $tags = array();
            foreach ($item->getTags() as $tag)
            {
                $tags[] = $tag->name;
            }

            if (!empty($tags))
            {
                $this->facetData['tag'] = $tags;
                $this->setField('tags', $tags);
            }
        }
    }

    protected function getImageUrl($itemId, $thumbnail)
    {
        $itemImageUrl = $this->getItemFileUrl($thumbnail);

        if (empty($itemImageUrl))
        {
            $coverImageIdentifier = ItemPreview::getCoverImageIdentifier($itemId);
            if (!empty($coverImageIdentifier))
            {
                $coverImageItem = ItemMetadata::getItemFromIdentifier($coverImageIdentifier);
                $itemImageUrl = empty($coverImageItem) ? '' : ItemPreview::getItemFileUrl($coverImageItem, $thumbnail);
            }
        }

        return $itemImageUrl;
    }

    public function extractTextFromPdf($filepath)
    {
        $path = escapeshellarg($filepath);

        // Attempt to extract the PDF file's text.
        //   The -nopgbrk option tells pdftotext not to emit formfeeds (\f) for page breaks.
        //   The trailing '-' at the end of the command says to emit the text to stdout instead of to a text file.
        $command = "pdftotext -enc UTF-8 -nopgbrk $path -";
        $pdfText = shell_exec($command);

        return $pdfText;
    }

    protected function getItemData($itemId, $titleString, $isPublic)
    {
        $itemData = array(
            'id' => $itemId,
            'title' => $titleString,
            'public' => (bool)$isPublic,
            'file-count' => count($this->itemFiles),
            'contributor' => $this->installation['contributor'],
            'contributor-id' => $this->installation['contributor-id']
        );
        return $itemData;
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
            $supportedImageMimeTypes = AvantCommon::supportedImageMimeTypes();
            $url = $file->getWebPath($thumbnail ? 'thumbnail' : 'original');

            if (!in_array($file->mime_type, $supportedImageMimeTypes))
            {
                // The original image is not a jpg (it's probably a pdf) so return its derivative image instead.
                $url = $file->getWebPath($thumbnail ? 'thumbnail' : 'fullsize');
            }
        }
        return $url;
    }

    protected function getItemPdfData()
    {
        $pdfMimeTypes = array(
            'application/pdf',
            'application/x-pdf',
            'application/acrobat',
            'text/x-pdf',
            'text/pdf',
            'applications/vnd.pdf'
        );

        $pdfData = array();
        $pdfTexts = array();
        $fileNames = array();
        $filePaths = array();

        foreach ($this->itemFiles as $file)
        {
            if (!in_array($file->mime_type, $pdfMimeTypes))
            {
                // This is not a PDF file.
                continue;
            }

            // Attempt to extract the PDF file's text.
            $fileName = $file->filename;
            $filepath = $this->getItemPdfFilepath('original', $fileName);
            if (!file_exists($filepath))
            {
                // This installation does not have its files at the root of the 'original' folder. Check to see if
                // the files are located in a sub directory having the item identifier as its name.
                $itemIdentifier = $this->elementData['identifier'];
                $filepath = $this->getItemPdfFilepath('original' . DIRECTORY_SEPARATOR . $itemIdentifier, $fileName);
                if (!file_exists($filepath))
                {
                    // This should never happen, but if it does, skip to the next file.
                    break;
                }
            }

            $text = $this->extractTextFromPdf($filepath);

            if (!is_string($text))
            {
                // This can happen in these two cases and possibly others:
                // 1. The string is null because the PDF has no content, probably because it has not been OCR'd.
                // 2. pdftotext is not installed on the host system and so the shell exec returned null.
                continue;
            }

            // Strip non ASCII characters from the text.
            $text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', ' ', $text);

            // Record the PDF's text and its file name in parallel arrays so we know which file contains which text.
            $pdfTexts[] = $text;
            $fileNames[] = $file->original_filename;
            $filePaths[] = $file->getWebPath('original');
        }

        if (!empty($pdfTexts) && !empty($fileNames))
        {
            foreach ($fileNames as $index => $fileName)
            {
                $pdfData["text-$index"] = $pdfTexts[$index];
                $pdfData['file-name'][] = $fileName;
                $pdfData['file-url'][] = $filePaths[$index];
            }
        }

        return $pdfData;
    }

    protected function getItemPdfFilepath($directory, $filename)
    {
        return FILES_DIR . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $filename;
    }

    protected function getItemUrlData($itemId)
    {
        $itemPath = $this->installation['item_path'] . $itemId;
        $serverUrl = $this->installation['server_url'];
        $itemUrl = $serverUrl . $itemPath;
        $thumbUrl = $this->getImageUrl($itemId, true);
        $imageUrl = $this->getImageUrl($itemId, false);

        $urlData = array(
            'item' => $itemUrl,
            'thumb' => $thumbUrl,
            'image' => $imageUrl
        );

        return $urlData;
    }

    public function pdfSearchingIsSupported()
    {
        $path = AVANTELASTICSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'pdftotext-test.pdf';
        $pdfText = $this->extractTextFromPdf($path);
        $pdfSearchingIsSupported = !empty($pdfText);
        return $pdfSearchingIsSupported;
    }

    protected function removeHtmlTagsFromFieldText(&$fieldTexts)
    {
        foreach ($fieldTexts as $key => $fieldText)
        {
            if ($fieldText['html'] == 1)
            {
                // Change any HTML content to plain text so that Elasticsearch won't get hits on HTML tags. For
                // example, if the query contained 'strong' we don't want the search to find the <strong> tag.
                $fieldTexts[$key]['text'] = strip_tags($fieldText['text']);
            }
        }
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

    public function setInstallationParameters($installation)
    {
        $this->installation = $installation;
    }

    protected function setSpecialFieldFlags($elasticsearchFieldName, $fieldTextsString, $fieldTexts)
    {
        if ($elasticsearchFieldName == 'title')
        {
            $this->titleString = $fieldTextsString;
            if (strlen($this->titleString) == 0)
            {
                $this->titleString = __('Untitled');
            }
            $this->titleFieldTexts = $fieldTexts;
        }

        if ($elasticsearchFieldName == 'identifier')
        {
            $this->itemHasIdentifier = true;
        }

        if ($elasticsearchFieldName == 'date')
        {
            $this->itemHasDate = true;
        }

        if ($elasticsearchFieldName == 'type')
        {
            // TO-DO: Make this logic generic so it doesn't depend on knowledge of specific type and subject values.
            $this->itemTypeIsReference = $fieldTextsString == 'Reference';
        }

        if ($elasticsearchFieldName == 'subject')
        {
            $this->itemSubjectIsPeople = strpos($fieldTextsString, 'People') !== false;
        }
    }
}