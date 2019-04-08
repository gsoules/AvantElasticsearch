<?php
class AvantElasticsearchMappings extends AvantElasticsearch
{
    protected $properties = array();

    protected function addFieldKeywordMappingToProperties($fieldName)
    {
        $this->properties[$fieldName] = [
            'type' => 'text',
            'fields' => [
                'keyword' => [
                    'type' => 'keyword',
                    'ignore_above' => 64
                ]
            ]
        ];
    }

    public function constructElasticsearchMapping()
    {
        $elementSet = $this->getElementsForMapping();
        $mappingType = $this->getDocumentMappingType();

        $this->properties['title'] = [
            'type' => 'text',
            'analyzer' => 'english'
        ];

        $this->properties['element.description'] = [
            'type' => 'text',
            'analyzer' => 'english'
        ];

        foreach ($elementSet as $element)
        {
            $elementName = $element['name'];

            if ($elementName == 'Description')
            {
                continue;
            }

            $fieldName = $this->convertElementNameToElasticsearchFieldName($elementName);

            $this->properties["element.$fieldName"] = [
                'type' => 'text',
                'fields' => [
                    'keyword' => [
                        'type' => 'keyword',
                        'ignore_above' => 128
                    ]
                ]
            ];
        }

        $this->addFieldKeywordMappingToProperties('image');
        $this->addFieldKeywordMappingToProperties('owner');
        $this->addFieldKeywordMappingToProperties('ownerid');
        $this->addFieldKeywordMappingToProperties('thumb');
        $this->addFieldKeywordMappingToProperties('url');

        $this->addFieldKeywordMappingToProperties('address-number');
        $this->addFieldKeywordMappingToProperties('address-street');

        $this->addFieldKeywordMappingToProperties('sort.place');
        $this->addFieldKeywordMappingToProperties('sort.subject');
        $this->addFieldKeywordMappingToProperties('sort.type');

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