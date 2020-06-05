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
    protected $allTitlesString = '';
    protected $avantElasticsearchFacets;
    protected $descriptionString;
    protected $facetDefinitions;
    protected $itemHasDate = false;
    protected $itemHasPlace = false;
    protected $itemHasSubject = false;
    protected $itemHasType = false;
    protected $itemHasIdentifier = false;
    protected $itemTypeIsReference = false;
    protected $itemSubjectIsPeople = false;
    protected $titleFieldTexts = null;
    protected $year = 0;

    // Arrays for collecting multiple values.
    protected $commonElementData = [];
    protected $localElementData = [];
    protected $privateElementData = [];
    protected $sortData = [];
    protected $facetDataCommon = [];
    protected $facetDataLocal = [];
    protected $htmlFields = [];

    public function __construct($indexName, $documentId)
    {
        parent::__construct();

        $this->setIndexName($indexName);
        $this->id = $documentId;
        $this->index = $indexName;
        $this->type = $this->getDocumentMappingType();
    }

    protected function addItemDataToDocumentBody($itemData, $excludePrivateFields)
    {
        if (!empty($this->htmlFields))
            $this->setField('html-fields', $this->htmlFields);

        $urlData = $this->getItemUrlData($itemData);
        $this->setField('url', $urlData);

        $fileText = $this->getItemFileText($itemData);
        if (!empty($fileText))
            $this->setField('pdf', $fileText);

        $itemAttributes = $this->getItemAttributes($itemData, $this->allTitlesString, $this->year, $this->descriptionString);
        $this->setField('item', $itemAttributes);

        $fileCounts = $this->getFileCounts($itemData);
        $this->setField('file', $fileCounts);

        $this->setField('common', $this->commonElementData);
        $this->setField('local', $this->localElementData);
        $this->setField('sort', $this->sortData);
        $this->setField('facet-common', $this->facetDataCommon);
        $this->setField('facet-local', $this->facetDataLocal);

        if (!$excludePrivateFields)
            $this->setField('private', $this->privateElementData);
    }

    protected function catentateElementTexts($fieldTexts)
    {
        // Get the element's text and catentate them into a single string separating each by a space.
        $catenatedText = '';
        foreach ($fieldTexts as $fieldText)
        {
            if (!empty($catenatedText))
            {
                $catenatedText .= ' ';
            }
            $catenatedText .= $fieldText['text'];
        }
        return $catenatedText;
    }

    protected function convertFieldValueToSortText($fieldTextValue)
    {
        // Convert the value to lowercase and remove any non alphanumeric characters and leading spaces to allow
        // case-insensitive sorting and so that values with leading non alphanumeric characters will sort correctly and
        // not appear at the top. Note also, because element fields, as opposed to sort fields, contain their data in an
        // array of values, even if there's only a single value, they cannot be used for sorting because there's no way
        // to get Elastcisearch to sort based only on the the first value in the array. This solution allows sorting
        // in general, but also provides better sorting because of the lower casing and leading character stripping.
        $sortText = preg_replace('/[^a-z\d ]/i', '', strtolower(trim($fieldTextValue)));

        // Return just up to the first 100 characters which is plenty for sorting purposes.
        return substr($sortText, 0, 100);
    }

    public function copyItemElementValuesToDocument($itemData, $excludePrivateFields)
    {
        $itemFieldTexts = $itemData['field_texts'];

        $commonVocabularyEnabled = plugin_is_active('AvantVocabulary');

        $vocabularyKinds = $this->installation['vocabularies']['kinds'];
        $vocabularyMappings = $this->installation['vocabularies']['mappings'];

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            $originalFieldTexts = $fieldTexts;
            $useCommonVocabulary = $commonVocabularyEnabled && array_key_exists($elementId, $vocabularyKinds);

            if ($useCommonVocabulary)
            {
                // Translate each of this element's values to their common vocabulary equivalents as though
                // the translations were the original values. This makes the indexing and facet logic use
                // the mapped values for common terms without affecting the local element values.
                $kind = $vocabularyKinds[$elementId];
                $mappings = $vocabularyMappings[$kind];
                foreach ($fieldTexts as $index => $fieldText)
                {
                    $commonTerm = '';
                    $localTerm = $fieldText['text'];
                    if (array_key_exists($localTerm, $mappings))
                    {
                        $commonTerm = $mappings[$localTerm];
                    }
                    else
                    {
                        // This should never happen, but for now, detect if it does.
                        $commonTerm = 'UNTRACKED';
                    }
                    $commonTerm = $commonTerm ? $commonTerm : 'UNMAPPED';
                    $fieldTexts[$index]['text'] = $commonTerm;
                }
            }
            else
            {
                $originalFieldTexts = [];
            }
            $this->createFieldDataForElement($elementId, $fieldTexts, $originalFieldTexts, $excludePrivateFields);
        }

        $this->createFacetDataForContributor();
        $this->createSpecialFieldsData($itemFieldTexts);
        $this->createSuggestionsData();
        $this->createTagData($itemData);

        $this->addItemDataToDocumentBody($itemData, $excludePrivateFields);
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

    protected function createFacetData($facetValue, $elasticsearchFieldName, $facetData)
    {
        if (is_array($facetValue))
        {
            // When the value is an array, the components are always root and leaf.
            // Add the root and leaf values to the facets array.
            $rootName = $facetValue['root'];
            $leafName = $facetValue['leaf'];
            $facetData["$elasticsearchFieldName.root"][] = $rootName;
            $facetData["$elasticsearchFieldName.leaf"][] = $leafName;

            // If the leaf has a grandchild, add the root and first child name to the facets array.
            // This will allow the user to use facets to filter by root, by first child, or by leaf.
            $rootAndFirstChild = $this->avantElasticsearchFacets->getRootAndFirstChildNameFromLeafName($leafName);
            if ($rootAndFirstChild != $leafName)
            {
                $facetData["$elasticsearchFieldName.leaf"][] = $rootAndFirstChild;
            }
        }
        else
        {
            $facetData[$elasticsearchFieldName][] = $facetValue;
        }

        return $facetData;
    }

    protected function createFacetDataForField($elasticsearchFieldName, $fieldTextsCommon, $fieldTextsLocal = null)
    {
        if (!isset($this->facetDefinitions[$elasticsearchFieldName]))
        {
            // This field is not used as a facet.
            return;
        }

        if (!$fieldTextsLocal)
            $fieldTextsLocal = $fieldTextsCommon;

        // Create the common facets.
        $facetValuesForElementCommon = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTextsCommon);
        foreach ($facetValuesForElementCommon as $facetValue)
        {
            $this->facetDataCommon = $this->createFacetData($facetValue, $elasticsearchFieldName, $this->facetDataCommon);
        }

        // Create the local facets.
        $facetValuesForElementLocal = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTextsLocal);
        foreach ($facetValuesForElementLocal as $facetValue)
        {
            $this->facetDataLocal = $this->createFacetData($facetValue, $elasticsearchFieldName, $this->facetDataLocal);
        }
    }

    protected function createFieldDataForElement($elementId, $fieldTexts, $originalFieldTexts, $excludePrivateFields)
    {
        // Get the element's field name.
        $fieldName = $this->installation['all_contributor_fields'][$elementId];

        // Strip any HTML tags from the field's text value(s).
        $this->removeHtmlTagsFromFieldText($fieldTexts);

        // Get the field names from the installation cache instead of calling the functions that get the names.
        $commonFields = $this->installation['common_fields'];
        $localFields = $this->installation['local_fields'];
        $privateFields = $this->installation['private_fields'];

        foreach ($fieldTexts as $index => $fieldText)
        {
            $fieldTextValue = $fieldText['text'];

            if ($index == 0)
            {
                // This is the first or the only value for an Omeka element. Use its value for sorting this element.
                $sortText = $this->convertFieldValueToSortText($fieldTextValue);
                $this->sortData[$fieldName] = $sortText;
            }

            // Copy the text to its corresponding field:
            // -  common: fields that are common to all contributors
            // -   local: fields that are unique to individual contributors
            // - private: fields that are private for individual contributors
            if (in_array($fieldName, $commonFields))
            {
                $this->commonElementData[$fieldName][] = $fieldTextValue;

                // Determine if the field values for this element were mapped to a common vocabulary by seeing if any
                // original texts were passed. If yes, and if the mapped and original texts are different, add the
                // original value to the local elements. This way the mapped value will end up in the common elements
                // and the original will end up in the local elements, so both will be searchable.
                $usingCommonVocabulary = count($originalFieldTexts) > 0;
                if ($usingCommonVocabulary)
                {
                    $originalFieldTextValue = $originalFieldTexts[$index]['text'];
                    if ($originalFieldTextValue != $fieldTextValue)
                    {
                        $this->localElementData[$fieldName][] = $originalFieldTextValue;
                    }
                }
            }
            else if (in_array($fieldName, $localFields))
            {
                $this->localElementData[$fieldName][] = $fieldTextValue;
            }
            else if (!$excludePrivateFields && in_array($fieldName, $privateFields))
            {
                $this->privateElementData[$fieldName][] = $fieldTextValue;
            }
        }

        // Set flags to indicate if this field requires special handling.
        $this->setSpecialFieldFlags($fieldName, $fieldTexts);

        // Create the various kinds of data associated with this field.
        $this->createHtmlData($fieldName, $fieldTexts);
        $this->createIntegerElementSortData($fieldName, $fieldTexts[0]['text']);
        $this->createAddressElementSortData($fieldName, $fieldTexts);
        $this->createFacetDataForField($fieldName, $fieldTexts, $originalFieldTexts);
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
            if (is_numeric($textString))
            {
                // Pad the beginning of the value with leading zeros so that integers can be sorted correctly as text.
                $this->sortData[$elasticsearchFieldName] = sprintf('%010d', $textString);
            }
        }
    }

    protected function createSpecialFieldsData($itemFieldTexts)
    {
        if ($this->installation['alias_id'] != 0)
        {
            // This installation does not use the Identifier element because it has an Identifier Alias
            // configured in AvantCommon. Get the alias value and use it as the identifier field value.
            $id = $this->installation['alias_id'];
            $aliasText = isset($itemFieldTexts[$id][0]['text']) ? $itemFieldTexts[$id][0]['text'] : BLANK_FIELD_TEXT;
            $this->commonElementData['identifier'][0] = $aliasText;
        }

        // Create a field-text to represent an unspecified date, place, subject, or type.
        $blankFieldTexts = $this->createFieldTexts(BLANK_FIELD_TEXT);

        if (!$this->itemHasDate)
        {
            $this->createFacetDataForField('date', $blankFieldTexts);
        }

        if (!$this->itemHasPlace)
        {
            $this->createFacetDataForField('place', $blankFieldTexts);
        }

        if (!$this->itemHasSubject)
        {
            $this->createFacetDataForField('subject', $blankFieldTexts);
        }

        if (!$this->itemHasType)
        {
            $this->createFacetDataForField('type', $blankFieldTexts);
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
            $this->facetDataLocal['tag'] = $tagsData;
            $this->setField('tags', $tagsData);
        }
    }

    protected function getCoverImageUrl($itemData, $thumbnail)
    {
        $itemImageUrl = '';
        $coverImageIdentifier = ItemPreview::getCoverImageIdentifier($itemData['id']);

        if (!empty($coverImageIdentifier))
        {
            $coverImageItem = ItemMetadata::getItemFromIdentifier($coverImageIdentifier);
            $itemImageUrl = empty($coverImageItem) ? '' : ItemPreview::getItemFileUrl($coverImageItem, $thumbnail);
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

    protected function getFileCounts($itemData)
    {
        $audio = 0;
        $document = 0;
        $image = 0;
        $video = 0;

        // This test for applicable mime types is overly simplistic given how many
        // mime types exist, but it's sufficient for statistics gathering purposes.
        foreach ($itemData['files_data'] as $fileData)
        {
            $mimeType = $fileData['mime_type'];
            if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'text') !== false)
            {
                $document++;
            }
            else if (strpos($mimeType, 'image') !== false)
            {
                $image++;
            }
            else if (strpos($mimeType, 'audio') !== false)
            {
                $audio++;
            }
            else if (strpos($mimeType, 'video') !== false)
            {
                $video++;
            }
        }

        $total = $audio + $document + $image + $video;

        $fileCounts = array(
            'audio' => $audio,
            'document' => $document,
            'image' => $image,
            'total' => $total,
            'video' => $video
        );

        foreach ($fileCounts as $key => $fileCount)
        {
            if ($fileCount == 0 && $key != 'total')
            {
                unset($fileCounts[$key]);
            }
        }

        return $fileCounts;
    }

    protected function getItemAttributes($itemData, $titleString, $year, $descriptionString)
    {
        $itemAttributes = array(
            'id' => $itemData['id'],
            'title' => $titleString,
            'description' => $descriptionString,
            'public' => (bool)$itemData['public'],
            'contributor' => $this->installation['contributor'],
            'contributor-id' => $this->installation['contributor_id']
        );

        if ($year > 0)
            $itemAttributes['year'] = $year;

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

        // Return a path with all forward slashes to avoid having mixed slashes in the URL.
        return str_replace('\\', '/', $webPath);
    }

    protected function getItemFileText($itemData)
    {
        $textFileMimeTypes = array(
            'application/pdf',
            'text/plain'
        );

        $fileData = array();
        $fileTexts = array();
        $fileNames = array();
        $filePaths = array();

        foreach ($itemData['files_data'] as $data)
        {
            if (!in_array($data['mime_type'], $textFileMimeTypes))
            {
                // This is not a file that we know how to get text from.
                continue;
            }

            // Attempt to extract the file's text.
            $fileName = $data['filename'];
            $filepath = $this->getItemPdfFilepath('original', $fileName);
            if (!file_exists($filepath))
            {
                // This installation does not have its files at the root of the 'original' folder. Check to see if
                // the files are located in a sub directory having the item identifier as its name.
                $itemIdentifier = $this->commonElementData['identifier'][0];
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

        // TEMP
        if (strpos($itemUrl, 'public_html') !== false)
        {
            $queryArgs = urldecode(http_build_query($_GET));
            $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '<not set>';
            $e = new Exception();
            $trace = $e->getTraceAsString();
            $body = '';
            $body .= 'ITEM:' . PHP_EOL . $itemData['identifier'];
            $body .= PHP_EOL . PHP_EOL . 'REQUEST URI:' . PHP_EOL . $requestUri;
            $body .= PHP_EOL . PHP_EOL . 'SERVER URL:' . PHP_EOL . $serverUrl;
            $body .= PHP_EOL . PHP_EOL . 'ITEM PATH:' . PHP_EOL . $itemPath;
            $body .= PHP_EOL . PHP_EOL . 'QUERY:' . PHP_EOL . $queryArgs;
            $body .= PHP_EOL . PHP_EOL . 'TRACE:' . PHP_EOL . $trace;
            AvantCommon::sendEmailToAdministrator('ES Error', 'COA item.url', $body);
        }
        // END TEMP

        $isCoverImage = false;
        $thumbnail = true;
        $thumbUrl = $this->getItemImageFileUrl($itemData, $thumbnail);
        if (empty($thumbUrl))
        {
            $thumbUrl = $this->getCoverImageUrl($itemData, $thumbnail);
            $isCoverImage = !empty($thumbUrl);
        }

        $thumbnail = false;
        $imageUrl = $this->getItemImageFileUrl($itemData, $thumbnail);
        if (empty($imageUrl))
        {
            $imageUrl = $this->getCoverImageUrl($itemData, $thumbnail);
        }

        $urlData['item'] = $itemUrl;
        if (!empty($thumbUrl))
        {
            $urlData['thumb'] = $thumbUrl;
            $urlData['image'] = $imageUrl;
            $urlData['cover'] = $isCoverImage;
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

    public function setField($key, $value)
    {
        $this->body[$key] = $value;
    }

    public function setInstallationParameters($installation)
    {
        $this->installation = $installation;
    }

    protected function setSpecialFieldFlags($fieldName, $fieldTexts)
    {
        if ($fieldName == 'title')
        {
            // Create a single string containing the item's one or more titles.
            $this->allTitlesString = $this->catentateElementTexts($fieldTexts);

            if (strlen($this->allTitlesString) == 0)
            {
                $this->allTitlesString = UNTITLED_ITEM;
            }

            // Also keep the array of separate titles.
            $this->titleFieldTexts = $fieldTexts;
        }
        else
        {
            $fieldText = $fieldTexts[0]['text'];

            switch ($fieldName)
            {
                case 'description':
                    $this->descriptionString = $fieldText;
                    break;

                case 'identifier':
                    $this->itemHasIdentifier = true;
                    break;

                case 'date':
                    $this->itemHasDate = true;
                    $this->year = intval($this->getYearFromDate($fieldText));
                    break;

                case 'place':
                    $this->itemHasPlace = true;
                    break;

                case 'subject':
                    $this->itemHasSubject = true;
                    $this->itemSubjectIsPeople = strpos($fieldText, 'People') !== false;
                    break;

                case 'type':
                    $this->itemHasType = true;
                    $this->itemTypeIsReference = $fieldText == 'Reference';
                    break;
            }
        }
    }
}