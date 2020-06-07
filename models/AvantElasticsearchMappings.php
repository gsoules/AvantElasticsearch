<?php
class AvantElasticsearchMappings extends AvantElasticsearch
{
    protected $properties = array();

    protected function addBooleanFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'boolean'
        ];
    }

    protected function addBoostFields()
    {
        // Make a copy of the Title texts and the Description using the standard analyzer instead of the English analyzer.
        // This yields the best possible search results on Title and Description content because it allows boosting on
        // hits in these fields where the search terms exactly match the original text without the stemming done by the
        // English analyzer. The English analyzer increases results because it can match more loosely, e.g. it will
        // treat 'run' and 'running' the same, but ranking is more accurate with the Standard Analyzer. Boost values
        // are specified in AvantElasticsearchQueryBuilder::constructQueryMust() e.g. 'item.title^20' boosts title by 20.
        //
        // Be aware that the item.* fields are initialized by AvantElasticsearchDocument::getItemAttributes(). If you
        // add another field here, add it there as well.

        // Note: It seems that it should be possible to get the same boost behavior for Description by adding a
        // 'standard' text field which uses the 'standard' analyzer in addTextAndKeywordFieldToMappingProperties())
        // instead of creating a copy, but that did not work. Boosting element.description.standard seemed to
        // have no effect at all. As such, we'll do it this way until such time as we can figure out a better way.
        // This is a non-issue for Title because element.title a multi-value field containing an array of titles
        // whereas item.title is a single value field with the title texts catentated.
        //
        $this->addTextFieldToMappingProperties('item.title', 'standard');
        $this->addTextFieldToMappingProperties('item.description', 'standard');
    }

    protected function addCompletionFieldToMappingProperties($fieldName)
    {
        // Use the Standard analyzer because by default Elasticsearch uses the Simple analyzer for completion
        // fields. The Simple analyzer strips away numbers, but we want them e.g. for street addresses.

        $this->properties[$fieldName] = [
            'type' => 'completion',
            'analyzer' => 'standard'
        ];
    }

    protected function addKeywordFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'keyword'
        ];
    }

    protected function addNumericFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'integer'
        ];
    }

    protected function addTextFieldToMappingProperties($fieldName, $analyzer = 'english')
    {
        // To learn about text fields see: www.elastic.co/guide/en/elasticsearch/reference/master/text.html
        $this->properties[$fieldName] = [
            'type' => 'text',
            'analyzer' => $analyzer
        ];
    }

    protected function addTextAndKeywordFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text',
            'analyzer' => 'english',
            'fields' => [
                'keyword' => [
                    'type' => 'keyword'
                ],
                'lowercase' => [
                    'type' => 'keyword',
                    'normalizer' => 'lowerCaseNormalizer'
                ]
            ]
        ];
    }

    public function constructElasticsearchMappings($isSharedIndex)
    {
        $commonFields = $this->getFieldNamesOfCommonElements();
        $localFields = $this->getFieldNamesOfLocalElements();
        $privateFields = $this->getFieldNamesOfPrivateElements();

        // Provide both an element and sort mapping for every field. The element mapping is used for searching and the
        // sort mapping is used exclusively for sorting. Also, an element field contain all the values for a multi-value
        // element whereas a sort field only contains the first value of a multi-value element.
        foreach ($commonFields as $fieldName)
        {
            $this->addTextAndKeywordFieldToMappingProperties("common-fields.$fieldName");
            $this->addKeywordFieldToMappingProperties("sort.$fieldName");
        }

        if (!$isSharedIndex)
        {
            foreach ($localFields as $fieldName)
            {
                $this->addTextAndKeywordFieldToMappingProperties("local-fields.$fieldName");
                $this->addKeywordFieldToMappingProperties("sort.$fieldName");
            }

            foreach ($privateFields as $fieldName)
            {
                $this->addTextAndKeywordFieldToMappingProperties("private-fields.$fieldName");
                $this->addKeywordFieldToMappingProperties("sort.$fieldName");
            }
        }

        if (in_array('address', $commonFields) || !$isSharedIndex)
        {
            // Address is a special field that any installation can use, but unless it is a Common field
            // only emit these special address sorting fields for a local index.
            $this->addKeywordFieldToMappingProperties('sort.address-number');
            $this->addKeywordFieldToMappingProperties('sort.address-street');
        }

        // Specify special fields that will be used to influence document scores by boosting.
        $this->addBoostFields();

        // Tags are not an element, so add a fields for them.
        $this->addTextFieldToMappingProperties('tags');

        // Completion field.
        $this->addCompletionFieldToMappingProperties('suggestions');

        // Boolean fields.
        $this->addBooleanFieldToMappingProperties('item.public');
        $this->addBooleanFieldToMappingProperties('url.cover');

        // Numeric fields.
        $this->addNumericFieldToMappingProperties('file.audio');
        $this->addNumericFieldToMappingProperties('file.document');
        $this->addNumericFieldToMappingProperties('file.image');
        $this->addNumericFieldToMappingProperties('file.total');
        $this->addNumericFieldToMappingProperties('file.video');
        $this->addNumericFieldToMappingProperties('item.id');
        $this->addNumericFieldToMappingProperties('item.year');

        // Keyword fields. None of these require full-text search.
        // To learn about keyword fields see: www.elastic.co/guide/en/elasticsearch/reference/master/keyword.html
        $this->addKeywordFieldToMappingProperties('facet.contributor');
        $this->addKeywordFieldToMappingProperties('facet.date');
        $this->addKeywordFieldToMappingProperties('facet.place');
        $this->addKeywordFieldToMappingProperties('facet.subject.leaf');
        $this->addKeywordFieldToMappingProperties('facet.subject.root');
        $this->addKeywordFieldToMappingProperties('facet.tag');
        $this->addKeywordFieldToMappingProperties('facet.type.leaf');
        $this->addKeywordFieldToMappingProperties('facet.type.root');
        $this->addKeywordFieldToMappingProperties('html-fields');
        $this->addKeywordFieldToMappingProperties('item.contributor');
        $this->addKeywordFieldToMappingProperties('item.contributor-id');
        $this->addKeywordFieldToMappingProperties('pdf.file-name');
        $this->addKeywordFieldToMappingProperties('pdf.file-url');
        $this->addKeywordFieldToMappingProperties('url.image');
        $this->addKeywordFieldToMappingProperties('url.item');
        $this->addKeywordFieldToMappingProperties('url.thumb');

        // Dynamically map fields pdf.text-0, pdf-text-1, and so on. How many of these fields there are is determined
        // by the item with the most PDF file attachments (only PDFs that are searchable). For example, most items
        // will have zero or one PDF attachment, but if there's an item with 10 PDFs, fields up to pdf-text-9 will
        // created and mapped when that item is indexed.
        $templatePdf = (object) array(
            'pdf_text' => [
                'path_match' => 'pdf.text-*',
                    'mapping' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ]
                ]
            );

        $templateLocal = (object) array(
            'local_text' => [
                'path_match' => 'local.*',
                    'mapping' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ]
                ]
            );

        $mappingType = $this->getDocumentMappingType();

        $mappings = [
            $mappingType => [
                'properties' => $this->properties,
                'dynamic_templates' => [$templatePdf, $templateLocal]
            ]
        ];

        return $mappings;
    }

    public function constructElasticsearchSettings()
    {
        $settings = [
            'analysis' => [
                'normalizer' => [
                    'lowerCaseNormalizer' => [
                        'type' => 'custom',
                        'char_filter' => [],
                        'filter' => ['lowercase', 'asciifolding']
                    ]
                ]
            ]
        ];

        return $settings;
    }
}