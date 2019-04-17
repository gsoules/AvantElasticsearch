<?php

class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $facetDefinitions = array();

    public function __construct()
    {
        parent::__construct();
        $this->defineFacets();
    }

    protected function createFacet($id, $name, $isHierarchy = false)
    {
        $definition = array(
            'name' => $name,
            'is_date' => false,
            'is_hierarchy' => $isHierarchy,
            'show_root' => true,
            'multi_value' => false,
            'hidden' => false);

        $this->facetDefinitions[$id] = $definition;
    }

    public function createAddFacetLink($queryString, $facetToAdd, $facetValue)
    {
        $args = explode('&', $queryString);
        $addFacet = true;

        foreach ($args as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $facetArg = "facet_{$facetToAdd}[]";

            $target = "$facetArg=$facetValue";
            $argContainsTarget = $target == $arg;

            if ($argContainsTarget)
            {
                $addFacet = false;
                break;
            }
        }

        if ($addFacet)
        {
            $queryString = "$queryString&$target";
        }

        return $queryString;
    }

    public function createAggregationsForElasticsearchQuery()
    {
        // Create an array of aggregation terms.
        foreach ($this->facetDefinitions as $facetId => $definition)
        {
            $terms[$facetId] = [
                'terms' => [
                    'field' => "facet.$facetId.keyword",
                    'size' => 100,
                    'order' => ['_key' => 'asc']
                ]
            ];
        }

        // Convert the array into a nested object for the aggregation as required by Elasticsearch.
        $aggregations = (object)json_decode(json_encode($terms));

        return $aggregations;
    }

    protected function createFacetFilter($filters, $facets, $facetFieldName, $facetId)
    {
        if (isset($facets[$facetId]))
        {
            $values = $facets[$facetId];

            // Create a separate term filter for each value so that the filters are ANDed
            // as opposed to using a single 'terms' filter with multiple values that are ORed.
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$facetFieldName => $value]];
            }
        }
        return $filters;
    }

    public function createQueryStringWithFacets($query)
    {
        $terms = isset($query['query']) ? $query['query'] : '';
        $facets = isset($query['facet']) ? $query['facet'] : array();
        $queryString = "query=".urlencode($terms);

        foreach ($facets as $facetName => $facetValues)
        {
            if (!is_array($facetValues))
            {
                // This should only happen if the query string syntax in invalid because someone edited or mistyped it.
                break;
            }

            foreach ($facetValues as $facetValue)
            {
                $queryString .= '&'.urlencode("facet_{$facetName}[]") . '=' . urlencode($facetValue);
            }
        }

        return $queryString;
    }

    public function createRemoveFacetLink($queryString, $facetToRemove, $facetValue)
    {
        $beforeArgs = explode('&', $queryString);
        $afterArgs = array();

        foreach ($beforeArgs as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $facetArg = "facet_{$facetToRemove}[]";

            $target = "$facetArg=$facetValue";
            $argContainsTarget = $target == $arg;

            if (!$argContainsTarget)
            {
                // Keep this arg since it not the one to be removed.
                $afterArgs[] = $arg;
            }
        }
        return implode('&', $afterArgs);
    }

    public function getFacetFiltersForElasticsearchQuery($facets)
    {
        $filters = array();

        foreach ($this->facetDefinitions as $facetId => $facetName)
        {
            $filters = $this->createFacetFilter($filters, $facets, "facet.$facetId.keyword", $facetId);
        }

        return $filters;
    }

    protected function defineFacets()
    {
        // The order is the order in which facet names appear in the Filters section on the search results page.
        $this->createFacet('type', 'Item Types', true);

        $this->createFacet('subject', 'Subjects', true);
        $this->facetDefinitions['subject']['multi_value'] = true;

        $this->createFacet('place', 'Places', true);
        $this->facetDefinitions['place']['show_root'] = false;

        $this->createFacet('date', 'Dates');
        $this->facetDefinitions['date']['is_date'] = true;

        // Tags are fully supported, but for now don't show this facet.
        $this->createFacet('tag', 'Tags');
        $this->facetDefinitions['tag']['hidden'] = true;

        $this->createFacet('owner', 'Owner');
    }

    public function getFacetDefinitions()
    {
        return $this->facetDefinitions;
    }

    protected function getFacetValueForDate($text)
    {
        if ($text == '')
        {
            $value = __('Unknown');
        }
        else
        {
            $year = '';
            if (preg_match("/^.*(\d{4}).*$/", $text, $matches))
            {
                $year = $matches[1];
            }

            if (empty($year))
            {
                // Malformed date so just return the original value.
                $value = $text;
            }
            else
            {
                $decade = $year - ($year % 10);
                $value = $decade . "'s";
            }
        }

        return $value;
    }

    public function getFacetValuesForElement($elasticsearchFieldName, $fieldTexts)
    {
        $values = array();

        if (array_key_exists($elasticsearchFieldName, $this->facetDefinitions))
        {
            foreach ($fieldTexts as $fieldText)
            {
                $text = $fieldText['text'];

                $facetDefinition = $this->facetDefinitions[$elasticsearchFieldName];

                if ($facetDefinition['is_hierarchy'])
                {
                    // For hierarchy facets, get the root and leaf values.
                    $value = $this->getFacetValueForHierarchy($elasticsearchFieldName, $text);
                    $root = $value['root'];
                    $level2 = $value['level2'];
                    $leaf = $value['leaf'];

                    // Form the facet value using the root and the leaf, ignoring anything in the middle.
                    $separator = empty($root) || empty($leaf) ? '' : ', ';

                    if ($facetDefinition['show_root'])
                    {
                        if (empty($level2))
                        {
                            $values[] = $root . $separator . $leaf;
                        }
                        else if ($level2 == $leaf)
                        {
                            $values[] = $leaf;
                        }
                        else
                        {
                            $values[] = $level2 . $separator . $leaf;
                        }
                    }
                    else
                    {
                        $values[] = $root . $separator . $leaf;
                    }

                    if ($this->facetDefinitions[$elasticsearchFieldName]['show_root'])
                    {
                        if (!empty($root) && !empty($leaf))
                        {
                            // Emit just the root as the top of the hierarchy.
                            $values[] = '_' . $root;
                        }
                    }
                }
                else if ($this->facetDefinitions[$elasticsearchFieldName]['is_date'])
                {
                    $values[] = $this->getFacetValueForDate($text);
                }
            }
        }

        return $values;
    }

    protected function getFacetValueForHierarchy($elasticsearchFieldName, $text)
    {
        $root = '';
        $level2 = '';

        if ($this->facetDefinitions[$elasticsearchFieldName]['show_root'])
        {
            // Find the first comma, the one that follows the root value.
            $index = strpos($text, ', ');
            if ($index === false)
            {
                // The root is the entire string.
                $root = $text;
            }
            else
            {
                $root = trim(substr($text, 0, $index));
            }
        }

        // Find the last comma, the one that precedes the leaf value.
        $index = strrpos($text, ',', -1);
        if ($index === false)
        {
            // The leaf is the entire string.
            $leaf = $text;
        }
        else
        {
            $leaf = trim(substr($text, $index + 1));

            // Look for level 2 text (one level down from the root).
            $index = strpos($text, ', ');
            if ($index !== false)
            {
                // Get the rest of the text following the root.
                $tail = substr($text, $index + 2);
                $index = strpos($tail, ', ');
                if ($index === false)
                {
                    $level2 = $tail;
                }
                else
                {
                    $level2 = trim(substr($tail, 0, $index));
                }
            }
        }

        if ($root == $leaf)
        {
            // The leaf and root are the same so get rid of the leaf value.
            $leaf = '';
        }

        return array('root' => $root, 'level2' => $level2, 'leaf' => $leaf);
    }
}