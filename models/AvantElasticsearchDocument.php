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

    // Arrays pair that can contain different data for use in a local index than in a shared index.
    protected $coreFieldDataLocalIndex = [];
    protected $coreFieldDataSharedIndex = [];
    protected $facetDataLocalIndex = [];
    protected $facetDataSharedIndex = [];
    protected $sortDataLocalIndex = [];
    protected $sortDataSharedIndex = [];

    protected $htmlFields = [];
    protected $localFieldData = [];
    protected $privateFieldData = [];

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

        $this->setField('core-fields-local-index', $this->coreFieldDataLocalIndex);
        $this->setField('core-fields-shared-index', $this->coreFieldDataSharedIndex);
        $this->setField('facet-local-index', $this->facetDataLocalIndex);
        $this->setField('facet-shared-index', $this->facetDataSharedIndex);
        $this->setField('sort-local-index', $this->sortDataLocalIndex);
        $this->setField('sort-shared-index', $this->sortDataSharedIndex);
        $this->setField('local-fields', $this->localFieldData);

        if (!$excludePrivateFields)
            $this->setField('private-fields', $this->privateFieldData);
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

        $usingCommonVocabulary = plugin_is_active('AvantVocabulary');

        $vocabularyKinds = $this->installation['vocabularies']['kinds'];
        $vocabularyMappings = $this->installation['vocabularies']['mappings'];

        foreach ($itemFieldTexts as $elementId => $fieldTexts)
        {
            // Strip any HTML tags from the field's text value(s).
            $this->removeHtmlTagsFromFieldText($fieldTexts);

            if ($usingCommonVocabulary && array_key_exists($elementId, $vocabularyKinds))
            {
                // This element uses the Common Vocabulary. Get the common term for each of the element's local values.
                // The common value will be used in the shared index, and the local value will be used in the local
                // index. If the local value is not mapped to a common term, the local values gets used as the shared
                // index value. The 'mapped' flag is for the benefit of subsequent logic. The other 'mapping' flags are
                // are to make clear what's going on during development and debugging.
                $mappings = $vocabularyMappings[$vocabularyKinds[$elementId]];
                foreach ($fieldTexts as $index => $fieldText)
                {
                    $localTerm = $fieldText['text'];
                    $commonTerm = $this->getCommonTermForLocalTerm($localTerm, $mappings);
                    if (empty($commonTerm))
                    {
                        // The local value is not mapped to a common term.
                        $commonTerm = $localTerm;
                        $fieldTexts[$index]['mapping'] = 'unmapped';
                    }
                    elseif ($commonTerm != $localTerm)
                    {
                        // The local value is mapped to a common term that is different than the local value.
                        $fieldTexts[$index]['mapping'] = 'mapped';
                    }
                    else
                    {
                        // The local value is the same as a common term.
                        $fieldTexts[$index]['mapping'] = 'same';
                    }

                    $fieldTexts[$index]['text-shared-index'] = $commonTerm;
                }
            }
            else
            {
                // This element does not use the Common Vocabulary which means that both the local and shared
                // index will use the same value. Copy the local value to the shared value.
                foreach ($fieldTexts as $index => $fieldText)
                {
                    $fieldTexts[$index]['text-shared-index'] = $fieldTexts[$index]['text'];
                    $fieldTexts[$index]['mapping'] = 'none';
                }
            }

            $this->createFieldDataForElement($elementId, $fieldTexts, $excludePrivateFields);
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
                $this->sortDataLocalIndex[$elasticsearchFieldName . '-number'] = sprintf('%010d', $number);

                $this->sortDataLocalIndex[$elasticsearchFieldName . '-street'] = $matches[2];
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
            $rootIndex = "$elasticsearchFieldName.root";
            $leafName = $facetValue['leaf'];
            $leafIndex = "$elasticsearchFieldName.leaf";

            if (!isset($facetData[$rootIndex]) || !in_array($rootName, $facetData[$rootIndex]))
                $facetData[$rootIndex][] = $rootName;

            if (!isset($facetData[$leafIndex]) || !in_array($leafName, $facetData[$leafIndex]))
               $facetData[$leafIndex][] = $leafName;

            // Now add intermediate portions of the hierarchy. For example, if the root is 'Object' and the leaf is
            // 'Object,Recreational,Gear,Football' then add 'Object,Recreational' and 'Object,Recreational,Gear'
            // so that every level of the hierarchy has its own facet that can be searched.
            $parts = array_map('trim', explode(',', $leafName));
            $count = count($parts);
            if ($count > 2)
            {
                $partial = $parts[0];
                foreach ($parts as $index => $part)
                {
                    if ($index < 1)
                        continue;
                    if ($index == $count - 1)
                        break;
                    $partial .= ",$part";
                    if (!in_array($partial, $facetData[$leafIndex]))
                        $facetData[$leafIndex][] = $partial;
                }
            }
        }
        else
        {
            $facetData[$elasticsearchFieldName][] = $facetValue;
        }

        return $facetData;
    }

    protected function createFacetDataForField($elasticsearchFieldName, $fieldTexts)
    {
        if (!isset($this->facetDefinitions[$elasticsearchFieldName]))
        {
            // This field is not used as a facet.
            return;
        }

        // Create the common facets.
        $facetValuesForElementCommon = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTexts, true);
        foreach ($facetValuesForElementCommon as $facetValue)
        {
            $this->facetDataSharedIndex = $this->createFacetData($facetValue, $elasticsearchFieldName, $this->facetDataSharedIndex);
        }

        // Create the local facets.
        $facetValuesForElementLocal = $this->avantElasticsearchFacets->getFacetValuesForElement($elasticsearchFieldName, $fieldTexts, false);
        foreach ($facetValuesForElementLocal as $facetValue)
        {
            $this->facetDataLocalIndex = $this->createFacetData($facetValue, $elasticsearchFieldName, $this->facetDataLocalIndex);
        }
    }

    protected function createFieldDataForElement($elementId, $fieldTexts, $excludePrivateFields)
    {
        // Get the element's field name.
        $fieldName = $this->installation['all_contributor_fields'][$elementId];

        // Get the various kinds of field names. Core fields are ones that every shared site uses. Local fields are
        // ones that are unique to a specific site. Private fields are both unique and private to a specific site.
        $coreFieldNames = $this->installation['core_fields'];
        $localFieldNames = $this->installation['local_fields'];
        $privateFieldNames = $this->installation['private_fields'];

        // Loop over the values for this element. It's values plural since some elements can have multiple values. For
        // example, if an Omeka item has more than one Subject, its Subject element will have a separate values for each.
        foreach ($fieldTexts as $index => $fieldText)
        {
            // Get the shared and local index values. If there is no shared value, use the local value. A shared value
            // will only exist if the element uses the Common Vocabulary to map local values to common values.
            $localIndexValue = $fieldText['text'];
            $sharedIndexValue = $fieldText['text-shared-index'];

            if ($index == 0)
            {
                // This is the element's first value. Use it value for sorting purposes. If the element has additional
                // values, they won't be used for sorting. In a sorted list of Omeka items, the other values will
                // appear along with the value, but only this value will be in sort order.
                $this->sortDataLocalIndex[$fieldName] = $this->convertFieldValueToSortText($localIndexValue);
                $this->sortDataSharedIndex[$fieldName] = $this->convertFieldValueToSortText($sharedIndexValue);
            }

            // Copy the text to its corresponding field:
            if (in_array($fieldName, $coreFieldNames))
            {
                $this->coreFieldDataLocalIndex[$fieldName][] = $localIndexValue;

                if (isset($this->coreFieldDataSharedIndex[$fieldName]) && in_array($sharedIndexValue, $this->coreFieldDataSharedIndex[$fieldName]))
                {
                    // This value is already in the shared index data. This can happen when multiple local terms are
                    // mapped to the same common term. For example if local terms "Birds,Songbirds" and "Birds,Raptors"
                    // are both mapped to "Nature,Animals,Birds" then "Nature,Animals,Birds" will appear twice in the
                    // field texts as a shared index value.
                    continue;
                }

                $this->coreFieldDataSharedIndex[$fieldName][] = $sharedIndexValue;

                if ($fieldText['mapping'] == 'mapped')
                {
                    // This core field's local value is mapped to a different shared value. To allow the local index
                    // value to be searchable during a shared search, copy the local value to the local fields data.
                    // As further explanation, let's use the Subject field as an example. It is a core field
                    // which means it is used by all sites (all sites have a Subject element). In contrast, a field like
                    // "Address" would be a local field meaning that it is only used by specific sites. The Subject
                    //  field also uses the Common Vocabulary which means it can have a local value like
                    // "Birds, Songbirds" that is mapped to a Common Vocabulary term like "Nature, Animals, Birds".
                    // The local value gets used as the Subject in the local index and the mapped value gets used in
                    // the shared index. However, without the logic below, a shared search on "Songbirds" would come up
                    // empty because that word would not be in the shared index. To allow shared searching of mapped
                    // values, the code below adds the local value to the shared index by putting it in the index's
                    // local field data as though it were a separate local field. As such, the Subject field will appear
                    // in the core fields data with the value "Nature, Animals, Birds" and the Subject field will also
                    // appear in the local fields data with the value "Birds, Songbirds". Since a shared search queries
                    // both the core and local field data, the search will get a hit on "Songbirds" in the local data.
                    $this->localFieldData[$fieldName][] = $localIndexValue;
                }
            }
            else if (in_array($fieldName, $localFieldNames))
            {
                // Copy the local value since a local field never uses the Common Vocabulary.
                $this->localFieldData[$fieldName][] = $localIndexValue;
            }
            else if (!$excludePrivateFields && in_array($fieldName, $privateFieldNames))
            {
                // Copy the local value since a private field never uses the Common Vocabulary.
                $this->privateFieldData[$fieldName][] = $localIndexValue;
            }
        }

        // Set flags to indicate if this field requires special handling.
        $this->setSpecialFieldFlags($fieldName, $fieldTexts);

        // Create the various kinds of data associated with this field.
        $this->createHtmlData($fieldName, $fieldTexts);
        $this->createIntegerElementSortData($fieldName, $fieldTexts[0]['text']);
        $this->createAddressElementSortData($fieldName, $fieldTexts);
        $this->createFacetDataForField($fieldName, $fieldTexts);
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
                $this->sortDataLocalIndex[$elasticsearchFieldName] = sprintf('%010d', $textString);
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
            $this->coreFieldDataSharedIndex['identifier'][0] = $aliasText;
            $this->coreFieldDataLocalIndex['identifier'][0] = $aliasText;
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
            $this->facetDataLocalIndex['tag'] = $tagsData;
            $this->setField('tags', $tagsData);
        }
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

    public static function fixupDocumentBody($isSharedIndex, &$documentBody)
    {
        // Choose which data to use depending on whether the index is shared or local. The document body contains both
        // shared and local data, but only one or the other is kept. This fixup is necessary because the data file for
        // a bulk export contains both kinds of data which it stores in the keys ending with 'shared-index' and
        // 'local-index', however only one or the other goes into the index. This method choose which data to use,
        // inserts the correct keys (core-fields, facet, or sort), and then deletes the original keys.

        $coreFieldsKey = $isSharedIndex ? 'core-fields-shared-index' : 'core-fields-local-index';
        $documentBody['core-fields'] = $documentBody[$coreFieldsKey];
        unset($documentBody['core-fields-shared-index']);
        unset($documentBody['core-fields-local-index']);

        $facetKey = $isSharedIndex ? 'facet-shared-index' : 'facet-local-index';
        $documentBody['facet'] = $documentBody[$facetKey];
        unset($documentBody['facet-shared-index']);
        unset($documentBody['facet-local-index']);

        $sortKey = $isSharedIndex ? 'sort-shared-index' : 'sort-local-index';
        $documentBody['sort'] = $documentBody[$sortKey];
        unset($documentBody['sort-shared-index']);
        unset($documentBody['sort-local-index']);

        if (!$isSharedIndex)
        {
            // Remove any keys from local-fields that exist in core-fields. This part of the fixup is needed
            // because fields that use the common vocabulary (Type, Subject, and Place) which have a local value
            // mapped to a common value, get their mapped local value stored in local-fields and their common values
            // stored in core-fields. This overloaded use of local fields is done so that those local values are
            // searchable by a shared search. However, they are not needed for a local search since they exist in
            // core-fields. The code below removes them from local-fields so they are not duplicated in the index.
            foreach ($documentBody['local-fields'] as $fieldName => $localFieldValues)
            {
                foreach ($documentBody['core-fields'] as $coreFieldName => $coreFieldValues)
                {
                    if ($fieldName == $coreFieldName)
                    {
                        unset($documentBody['local-fields'][$coreFieldName]);
                    }
                }
            }
        }
    }

    protected function getCommonTermForLocalTerm($localTerm, $localToCommonMappings)
    {
        if (array_key_exists($localTerm, $localToCommonMappings))
        {
            // Get the common term. If the common term is empty, the local term is not mapped to a common term.
            $commonTerm = $localToCommonMappings[$localTerm];
        }
        else
        {
            // This should never happen under normal circumstances because the mappings table should contain an entry
            // for every local term defined using the Vocabulary Editor. However, if somehow an item uses a term that
            // we are not tracking, e.g. inserted through the Bulk Editor, we'll detect it here and handle gracefully.
            $commonTerm = 'UNTRACKED-LOCAL_TERM';
        }

        return $commonTerm;
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
            'modified' => $itemData['modified'],
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
                $itemIdentifier = $this->coreFieldDataLocalIndex['identifier'][0];
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