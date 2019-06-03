<?php

define ('IMAGE_FILE_TYPE_FULLSIZE', "fullsize");
define ('IMAGE_FILE_TYPE_ORIGINAL', "original");
define ('IMAGE_FILE_TYPE_THUMBNAIL', "thumbnails");


class AvantElasticsearchDocument extends AvantElasticsearch
{
    // These need to be public so that objects of this class can be JSON encoded/decoded.
    public $id;
    public $index;
    public $type;
    public $body = [];

    // Cached data.
    private $installation;

    /* @var $avantElasticsearchFacets AvantElasticsearchFacets */
    protected $avantElasticsearchFacets;
    protected $facetDefinitions;
    protected $itemHasDate = false;
    protected $itemHasPlace = false;
    protected $itemHasSubject = false;
    protected $itemHasType = false;
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

    public function __construct($indexName, $documentId)
    {
        parent::__construct();

        $this->setIndexName($indexName);
        $this->id = $documentId;
        $this->index = $indexName;
        $this->type = $this->getDocumentMappingType();
    }

    protected function addItemDataToDocumentBody($itemData)
    {
        if (!empty($this->htmlFields))
            $this->setField('html-fields', $this->htmlFields);

        $urlData = $this->getItemUrlData($itemData);
        $this->setField('url', $urlData);

        $fileText = $this->getItemFileText($itemData);
        if (!empty($fileText))
            $this->setField('pdf', $fileText);

        $itemAttributes = $this->getItemAttributes($itemData, $this->titleString);
        $this->setField('item', $itemAttributes);

        $this->setField('element', $this->elementData);
        $this->setField('sort', $this->sortData);
        $this->setField('facet', $this->facetData);
    }

    protected function catentateElementTexts($fieldTexts)
    {
        // Get the element's text and catentate them into a single string separate by EOL breaks.
        // Though Elasticsearch supports multiple field values stored in arrays, it does not support
        // sorting based on the first value as is required by AvantSearch when a user sorts by column.
        // By catenating the values, sorting will work as desired.

        $catenatedText = '';
        foreach ($fieldTexts as $fieldText)
        {
            if (!empty($catenatedText))
            {
                $catenatedText .= ES_DOCUMENT_EOL;
            }
            $catenatedText .= $fieldText['text'];
        }
        return $catenatedText;
    }

    public function copyItemElementValuesToDocument($itemData)
    {
        $itemId = $itemData['id'];
        $itemFieldTexts = $itemData['field_texts'];

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            $this->createFieldDataForElement($elementId, $fieldTexts);
        }

        $this->createFacetDataForContributor();
        $this->createSpecialFieldsData($itemFieldTexts);
        $this->createSuggestionsData();
        $this->createTagData($itemData);

        $this->addItemDataToDocumentBody($itemData);
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
        $this->createAddressElementSortData($elasticsearchFieldName, $fieldTexts);
        $this->createFacetDataForField($elasticsearchFieldName, $fieldTexts);
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


        // Create a field-text to represent an unspecified date, place, or subject.
        if (!$this->itemHasDate)
        {
            $fieldTexts = $this->createFieldTexts('[blank]');
            $this->createFacetDataForField('date', $fieldTexts);
        }

        if (!$this->itemHasPlace)
        {
            $fieldTexts = $this->createFieldTexts('[blank]');
            $this->createFacetDataForField('place', $fieldTexts);
        }

        if (!$this->itemHasSubject)
        {
            $fieldTexts = $this->createFieldTexts('[blank]');
            $this->createFacetDataForField('subject', $fieldTexts);
        }

        if (!$this->itemHasType)
        {
            $fieldTexts = $this->createFieldTexts('[blank]');
            $this->createFacetDataForField('type', $fieldTexts);
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

    protected function createTagData($itemData)
    {
        $tagsData = $itemData['tags_data'];
        if (!empty($tagsData))
        {
            $this->facetData['tag'] = $tagsData;
            $this->setField('tags', $tagsData);
        }
    }

    protected function getImageUrl($itemData, $thumbnail)
    {
        $itemImageUrl = $this->getItemImageFileUrl($itemData, $thumbnail);

        if (empty($itemImageUrl))
        {
            $coverImageIdentifier = ItemPreview::getCoverImageIdentifier($itemData['id']);
            if (!empty($coverImageIdentifier))
            {
                $coverImageItem = ItemMetadata::getItemFromIdentifier($coverImageIdentifier);
                $itemImageUrl = empty($coverImageItem) ? '' : ItemPreview::getItemFileUrl($coverImageItem, $thumbnail);
            }
        }

        return $itemImageUrl;
    }

    public static function extractTextFromPdf($filepath)
    {
        $path = escapeshellarg($filepath);

        // Attempt to extract the PDF file's text.
        //   The -nopgbrk option tells pdftotext not to emit formfeeds (\f) for page breaks.
        //   The trailing '-' at the end of the command says to emit the text to stdout instead of to a text file.
        $command = "pdftotext -enc UTF-8 -nopgbrk $path -";
        $pdfText = shell_exec($command);

        return $pdfText;
    }

    protected function getItemAttributes($itemData, $titleString)
    {
        $itemAttributes = array(
            'id' => $itemData['id'],
            'title' => $titleString,
            'public' => (bool)$itemData['public'],
            'file-count' => count($itemData['files_data']),
            'contributor' => $this->installation['contributor'],
            'contributor-id' => $this->installation['contributor-id']
        );
        return $itemAttributes;
    }

    protected function getItemImageFileUrl($itemData, $thumbnail)
    {
        $url = '';

        // Get the data for the item's first file. It's the only one we're interested in as the item's image file.
        $itemFilesData = $itemData['files_data'];
        $fileData = empty($itemFilesData) ? null : $itemFilesData[0];

        if (!empty($fileData) && $fileData['has_derivative_image'])
        {
            $supportedImageMimeTypes = AvantCommon::supportedImageMimeTypes();
            $url = $this->getItemImageFileWebPath($fileData, $thumbnail ? IMAGE_FILE_TYPE_THUMBNAIL : IMAGE_FILE_TYPE_ORIGINAL);

            if (!in_array($fileData['mime_type'], $supportedImageMimeTypes))
            {
                // The original image is not a jpg (it's probably a pdf) so return its derivative image instead.
                $url = $this->getItemImageFileWebPath($fileData, $thumbnail ? IMAGE_FILE_TYPE_THUMBNAIL : IMAGE_FILE_TYPE_FULLSIZE);
            }
        }
        return $url;
    }

    protected function getItemImageFileWebPath($fileData, $imageFileType)
    {
        $filePath = $this->installation['server_url'] . $this->installation['files_path'];
        $fileName = $fileData['filename'];
        
        if ($imageFileType != IMAGE_FILE_TYPE_ORIGINAL)
        {
            // A path to a derivative image is being requested. Change the file name extension to 'jpg'.
            $position = strrpos($fileName, '.');
            $fileName = substr($fileName, 0, $position);
            $fileName .= '.jpg';
        }
        $webPath = $filePath . DIRECTORY_SEPARATOR . $imageFileType . DIRECTORY_SEPARATOR . $fileName;
        return $webPath;
    }

    protected function getItemFileText($itemData)
    {
        $textFileMimeTypes = array(
            'application/pdf',
            'application/x-pdf',
            'application/acrobat',
            'text/x-pdf',
            'text/pdf',
            'text/plain',
            'applications/vnd.pdf'
        );

        $fileData = array();
        $fileTexts = array();
        $fileNames = array();
        $filePaths = array();

        foreach ($itemData['files_data'] as $data)
        {
            if (!in_array($data['mime_type'], $textFileMimeTypes))
            {
                // This is not file that we know how to get text from.
                continue;
            }

            // Attempt to extract the file's text.
            $fileName = $data['filename'];
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

            if ($data['mime_type'] == 'text/plain')
            {
                $text = file_get_contents($filepath);
            }
            else
            {
                $text = self::extractTextFromPdf($filepath);
            }

            if (!is_string($text))
            {
                // This can happen in these two cases and possibly others:
                // 1. The string is null because the PDF has no content, probably because it has not been OCR'd.
                // 2. pdftotext is not installed on the host system and so the shell exec returned null.
                continue;
            }

            // Strip non ASCII characters from the text.
            $text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', ' ', $text);

            // Record the file's text and its file name in parallel arrays so we know which file contains which text.
            $fileTexts[] = $text;
            $fileNames[] = $data['original_filename'];
            $filePaths[] = $this->getItemImageFileWebPath($data, IMAGE_FILE_TYPE_ORIGINAL);
        }

        if (!empty($fileTexts) && !empty($fileNames))
        {
            foreach ($fileNames as $index => $fileName)
            {
                $fileData["text-$index"] = $fileTexts[$index];
                $fileData['file-name'][] = $fileName;
                $fileData['file-url'][] = $filePaths[$index];
            }
        }

        return $fileData;
    }

    protected function getItemPdfFilepath($directory, $filename)
    {
        return FILES_DIR . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $filename;
    }

    protected function getItemUrlData($itemData)
    {
        $itemPath = $this->installation['item_path'] . $itemData['id'];
        $serverUrl = $this->installation['server_url'];
        $itemUrl = $serverUrl . $itemPath;
        $thumbUrl = $this->getImageUrl($itemData, true);
        $imageUrl = $this->getImageUrl($itemData, false);

        $urlData['item'] = $itemUrl;
        if (!empty($thumbUrl))
        {
            $urlData['thumb'] = $thumbUrl;
            $urlData['image'] = $imageUrl;
        }

        return $urlData;
    }

    public static function pdfSearchingIsSupported()
    {
        $path = AVANTELASTICSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'pdftotext-test.pdf';
        $pdfText = self::extractTextFromPdf($path);
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
                $this->titleString = UNTITLED_ITEM;
            }
            $this->titleFieldTexts = $fieldTexts;
        }

        if ($elasticsearchFieldName == 'identifier')
            $this->itemHasIdentifier = true;

        if ($elasticsearchFieldName == 'date')
            $this->itemHasDate = true;

        if ($elasticsearchFieldName == 'place')
            $this->itemHasPlace = true;

        if ($elasticsearchFieldName == 'subject')
        {
            $this->itemHasSubject = true;
            $this->itemSubjectIsPeople = strpos($fieldTextsString, 'People') !== false;
        }

        if ($elasticsearchFieldName == 'type')
        {
            $this->itemHasType = true;
            $this->itemTypeIsReference = $fieldTextsString == 'Reference';
        }
    }
}