<?php
class AvantElasticsearchMappings extends AvantElasticsearch
{
    protected $properties = array();

    protected function addAnalyzerFieldToMappingProperties($fieldName)
    {
        $this->properties['$fieldName'] = [
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
        $elementSet = $this->getElementsForMapping();
        $mappingType = $this->getDocumentMappingType();

        foreach ($elementSet as $element)
        {
            $elementName = $element['name'];

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
        $this->addTextFieldToMappingProperties('image');
        $this->addTextFieldToMappingProperties('owner');
        $this->addTextFieldToMappingProperties('ownerid');
        $this->addTextFieldToMappingProperties('thumb');
        $this->addTextFieldToMappingProperties('url');

        $this->addTextFieldToMappingProperties('facet.date');
        $this->addTextFieldToMappingProperties('facet.place');
        $this->addTextFieldToMappingProperties('facet.subject');
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

    protected function getElementsForMapping()
    {
        $table = get_db()->getTable('Element');
        $select = $table->getSelect()->order('element_set_id ASC')->order('order ASC');
        $elementSet = $table->fetchObjects($select);

        $hidePrivate = true;
        $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
        $unusedElementsData = CommonConfig::getOptionDataForUnusedElements();

        foreach ($elementSet as $elementName => $element)
        {
            $elementId = $element->id;
            $hideUnused = array_key_exists($elementId, $unusedElementsData);
            $hide = $hideUnused || ($hidePrivate && array_key_exists($elementId, $privateElementsData));
            if ($hide)
            {
                unset($elementSet[$elementName]);
            }
        }

        return $elementSet;
    }
}