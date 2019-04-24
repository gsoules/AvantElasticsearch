<?php
class AvantElasticsearchMappings extends AvantElasticsearch
{
    protected $properties = array();

    protected function addAnalyzerFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text',
            'analyzer' => 'english'
        ];
    }

    protected function addBooleanFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'boolean'
        ];
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

    protected function addTextAndKeywordFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text',
            'fields' => [
                'keyword' => [
                    'type' => 'keyword',
                    'ignore_above' => 128
                ]
            ]
        ];
    }

    public function constructElasticsearchMapping()
    {
        $elements = $this->getElementsUsedByThisInstallation();
        $mappingType = $this->getDocumentMappingType();

        foreach ($elements as $elementName)
        {
            if ($elementName == 'Description')
            {
                // The text field is added separately below because it uses a different analyzer.
                continue;
            }

            // Create a text and keyword mapping for item elements. The text fields is needed to allow full-text
            // search of the field. The keyword mapping is necessary for sorting.
            $fieldName = $this->convertElementNameToElasticsearchFieldName($elementName);
            $this->addTextAndKeywordFieldToMappingProperties("element.$fieldName");
        }

        // Analyzer fields. These are text fields for full-text search, but don't need to also be keyword fields.
        // Note that the 'title' field is a copy of the 'element.title' field. This one uses the English analyzer
        // and the other doesn't in order to get the best possible search results on title content.
        $this->addAnalyzerFieldToMappingProperties('element.description');
        $this->addAnalyzerFieldToMappingProperties('title');

        // Completion field.
        $this->addCompletionFieldToMappingProperties('suggestions');

        // Boolean fields.
        $this->addBooleanFieldToMappingProperties('public');

        // Numeric fields.
        $this->addNumericFieldToMappingProperties('files');
        $this->addNumericFieldToMappingProperties('itemid');

        // Keyword fields. None of these require full-text search.
        $this->addKeywordFieldToMappingProperties('html');
        $this->addKeywordFieldToMappingProperties('tags');
        $this->addKeywordFieldToMappingProperties('image');
        $this->addKeywordFieldToMappingProperties('contributor');
        $this->addKeywordFieldToMappingProperties('contributorid');
        $this->addKeywordFieldToMappingProperties('thumb');
        $this->addKeywordFieldToMappingProperties('url');

        $this->addKeywordFieldToMappingProperties('facet.date');
        $this->addKeywordFieldToMappingProperties('facet.contributor');
        $this->addKeywordFieldToMappingProperties('facet.place');
        $this->addKeywordFieldToMappingProperties('facet.subject.leaf');
        $this->addKeywordFieldToMappingProperties('facet.subject.root');
        $this->addKeywordFieldToMappingProperties('facet.tag');
        $this->addKeywordFieldToMappingProperties('facet.type.leaf');
        $this->addKeywordFieldToMappingProperties('facet.type.root');

        $this->addKeywordFieldToMappingProperties('sort.address-number');
        $this->addKeywordFieldToMappingProperties('sort.address-street');
        $this->addKeywordFieldToMappingProperties('sort.identifier');
        $this->addKeywordFieldToMappingProperties('sort.place');
        $this->addKeywordFieldToMappingProperties('sort.subject');
        $this->addKeywordFieldToMappingProperties('sort.type');

        $mapping = [
            $mappingType => [
                'properties' => $this->properties
            ]
        ];

        return $mapping;
    }
}