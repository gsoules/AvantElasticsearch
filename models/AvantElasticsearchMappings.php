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
                ]
            ]
        ];
    }

    public function constructElasticsearchMapping($isSharedIndex)
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

        // Text fields for elements that don't require a corresponding keyword field for sorting or aggregating
        // because these fields have a separate keyword field for those purposes.
        // To learn about text fields see: www.elastic.co/guide/en/elasticsearch/reference/master/text.html
        $textOnlyFields = array(
            'address',
            'description',
            'identifier'
        );

        foreach ($fieldNames as $fieldName)
        {
            // Create a text and keyword mapping for item elements. The text fields is needed to allow full-text
            // search of the field. The keyword mapping is necessary for sorting.
            if (in_array($fieldName, $textOnlyFields))
            {
                $this->addTextFieldToMappingProperties("element.$fieldName");
            }
            else
            {
                $this->addTextAndKeywordFieldToMappingProperties("element.$fieldName");
            }
        }

        // Tags are not an element, so add a fields for them.
        $this->addTextFieldToMappingProperties('tags');

        // The 'item.title' field is a copy of the 'element.title' field. This one uses the standard analyzer
        // whereas element.title uses the english analyzer. This yields the best possible search results on title content.
        $this->addTextFieldToMappingProperties('item.title', 'standard');

        // Completion field.
        $this->addCompletionFieldToMappingProperties('suggestions');

        // Boolean fields.
        $this->addBooleanFieldToMappingProperties('item.public');

        // Numeric fields.
        $this->addNumericFieldToMappingProperties('file.audio');
        $this->addNumericFieldToMappingProperties('file.document');
        $this->addNumericFieldToMappingProperties('file.image');
        $this->addNumericFieldToMappingProperties('file.total');
        $this->addNumericFieldToMappingProperties('file.video');
        $this->addNumericFieldToMappingProperties('item.id');

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
            "pdf_text" => [
                "path_match" => "pdf.text-*",
                    "mapping" => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ]
                ]
            );

        $mapping = [
            $mappingType => [
                'properties' => $this->properties,
                'dynamic_templates' => [$template]
            ]
        ];

        return $mapping;
    }
}