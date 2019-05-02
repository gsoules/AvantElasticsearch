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

    protected function addTextFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text'
        ];
    }

    protected function addTextAndKeywordFieldToMappingProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text',
            'fields' => [
                'keyword' => [
                    'type' => 'keyword'
                ]
            ]
        ];
    }

    public function constructElasticsearchMapping()
    {
        $elements = $this->getElementsUsedByThisInstallation();
        $mappingType = $this->getDocumentMappingType();

        // Text fields for elements that don't require a corresponding keyword field for sorting or aggregating
        // because these fields have a separate keyword field for those purposes.
        // To learn about text fields see: www.elastic.co/guide/en/elasticsearch/reference/master/text.html
        // TO-DO: Make text-only-fields list configurable so that site specific fields like datestart and status can be added.
        // Also make a keywordOnlyFields list for the same purpose.
        $textOnlyFields = array(
            'address',
            'identifier',
            'place',
            'subject',
            'type'
        );

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

            if (in_array($fieldName, $textOnlyFields))
            {
                $this->addTextFieldToMappingProperties("element.$fieldName");
            }
            else
            {
                $this->addTextAndKeywordFieldToMappingProperties("element.$fieldName");
            }
        }

        // Analyzer fields. These are text fields for full-text search, but don't need to also be keyword fields.
        // Note that the 'title' field is a copy of the 'element.title' field. This one uses the English analyzer
        // and the other doesn't in order to get the best possible search results on title content.
        $this->addAnalyzerFieldToMappingProperties('element.description');
        $this->addAnalyzerFieldToMappingProperties('item.title');

        // Fields for PDF searching.
        $this->addAnalyzerFieldToMappingProperties('pdf.text');
        $this->addKeywordFieldToMappingProperties('pdf.file-name');

        // Completion field.
        $this->addCompletionFieldToMappingProperties('suggestions');

        // Boolean fields.
        $this->addBooleanFieldToMappingProperties('item.public');

        // Numeric fields.
        $this->addNumericFieldToMappingProperties('item.file-count');
        $this->addNumericFieldToMappingProperties('item.id');

        // Keyword fields. None of these require full-text search.
        // To learn about keyword fields see: www.elastic.co/guide/en/elasticsearch/reference/master/keyword.html
        $this->addKeywordFieldToMappingProperties('html-fields');
        $this->addKeywordFieldToMappingProperties('item.contributor');
        $this->addKeywordFieldToMappingProperties('item.contributor-id');
        $this->addKeywordFieldToMappingProperties('tags');
        $this->addKeywordFieldToMappingProperties('url.image');
        $this->addKeywordFieldToMappingProperties('url.item');
        $this->addKeywordFieldToMappingProperties('url.thumb');
        $this->addKeywordFieldToMappingProperties('facet.contributor');
        $this->addKeywordFieldToMappingProperties('facet.date');
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