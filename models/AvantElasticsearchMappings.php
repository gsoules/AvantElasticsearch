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

    protected function addFacetFieldToMappingProperties($fieldName)
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

            $fieldName = $this->convertElementNameToElasticsearchFieldName($elementName);
            $this->addTextFieldToMappingProperties("element.$fieldName");
        }

        $this->addAnalyzerFieldToMappingProperties('element.description');
        $this->addAnalyzerFieldToMappingProperties('title');

        $this->addCompletionFieldToMappingProperties('suggestions');

        $this->addBooleanFieldToMappingProperties('public');

        $this->addNumericFieldToMappingProperties('files');
        $this->addNumericFieldToMappingProperties('itemid');

        $this->addTextFieldToMappingProperties('html');
        $this->addTextFieldToMappingProperties('tags');
        $this->addTextFieldToMappingProperties('image');
        $this->addTextFieldToMappingProperties('owner');
        $this->addTextFieldToMappingProperties('ownerid');
        $this->addTextFieldToMappingProperties('thumb');
        $this->addTextFieldToMappingProperties('url');

        $this->addFacetFieldToMappingProperties('facet.date');
        $this->addFacetFieldToMappingProperties('facet.tag');
        $this->addFacetFieldToMappingProperties('facet.place');
        $this->addFacetFieldToMappingProperties('facet.type.root');
        $this->addFacetFieldToMappingProperties('facet.type.leaf');
        $this->addFacetFieldToMappingProperties('facet.subject.root');
        $this->addFacetFieldToMappingProperties('facet.subject.leaf');

        $this->addTextFieldToMappingProperties('sort.address-number');
        $this->addTextFieldToMappingProperties('sort.address-street');
        $this->addTextFieldToMappingProperties('sort.identifier');
        $this->addTextFieldToMappingProperties('sort.place');
        $this->addTextFieldToMappingProperties('sort.subject');
        $this->addTextFieldToMappingProperties('sort.type');

        $mapping = [
            $mappingType => [
                'properties' => $this->properties
            ]
        ];

        return $mapping;
    }
}