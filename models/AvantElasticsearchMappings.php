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

        $this->addTextFieldToMappingProperties('facet.date');
        $this->addTextFieldToMappingProperties('facet.place');
        $this->addTextFieldToMappingProperties('facet.subject');
        $this->addTextFieldToMappingProperties('facet.tag');
        $this->addTextFieldToMappingProperties('facet.type');

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