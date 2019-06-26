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
        // Make a copy of the Title and Description fields using the standard analyzer instead of the English analyzer.
        // This yields the best possible search results on Title and Description content because it allows boosting on
        // hits in these fields where the search terms exactly match the original text without the stemming done by the
        // English analyzer. The English analyzer increases results because it can match more loosely, e.g. it will
        // treat 'run' and 'running' the same, but ranking is more accurate with the Standard Analyzer. Boost values
        // are specified in AvantElasticsearchQueryBuilder::constructQueryMust() e.g. 'item.title^20' boosts title by 20.
        //
        // Be aware that the item.* fields are initialized by AvantElasticsearchDocument::getItemAttributes(). If you
        // add another field here, add it there as well.

        // Note: It seems that it should be possible to get the same boost behavior by adding a 'standard' text field
        // which uses the 'standard' analyzer in addTextAndKeywordFieldToMappingProperties()) instead of creating these
        // copies, but that did not work. Boosting element.title.standard and element.description.standard seemed to
        // have no effect at all. As such, we'll do it this way until such time as we can figure out a better way.
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
        $fieldNames = array();

        if ($isSharedIndex)
        {
            $fieldNames = $this->getSharedIndexFieldNames();
        }
        else
        {
            $elementNames = $this->getElementsUsedByThisInstallation();
            foreach ($elementNames as $elementName)
            {
                $fieldNames[] = $this->convertElementNameToElasticsearchFieldName($elementName);
            }
        }

        $mappingType = $this->getDocumentMappingType();

        foreach ($fieldNames as $fieldName)
        {
            $this->addTextAndKeywordFieldToMappingProperties("element.$fieldName");
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
        $this->addKeywordFieldToMappingProperties('sort.address-number');
        $this->addKeywordFieldToMappingProperties('sort.address-street');
        $this->addKeywordFieldToMappingProperties('sort.identifier');
        $this->addKeywordFieldToMappingProperties('url.image');
        $this->addKeywordFieldToMappingProperties('url.item');
        $this->addKeywordFieldToMappingProperties('url.thumb');

        // Dynamically map fields pdf.text-0, pdf-text-1, and so on. How many of these fields there are is determined
        // by the item with the most PDF file attachments (only PDFs that are searchable). For example, most items
        // will have zero or one PDF attachment, but if there's an item with 10 PDFs, fields up to pdf-text-9 will
        // created and mapped when that item is indexed.
        $template = (object) array(
            'pdf_text' => [
                'path_match' => 'pdf.text-*',
                    'mapping' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ]
                ]
            );

        $mappings = [
            $mappingType => [
                'properties' => $this->properties,
                'dynamic_templates' => [$template]
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