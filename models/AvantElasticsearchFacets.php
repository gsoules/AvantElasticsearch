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
        // Create an array of aggregation terms. Return the results in ascending order instead of the
        // default which is descending by bucket quantity.
        foreach ($this->facetDefinitions as $facetId => $definition)
        {
            $terms[$facetId] = [
                'terms' => [
                    'field' => "facet.$facetId",
                    'size' => 1000,
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
        $this->createFacet('type', 'Item Type', true);

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

    public function emitHtmlForAppliedFilters($query, $findUrl)
    {
        $appliedFilters = '';

        $queryString = $this->createQueryStringWithFacets($query);
        $queryStringFacets = $query['facet'];

        foreach ($queryStringFacets as $facetId => $facetValues)
        {
            if (!isset($this->facetDefinitions[$facetId])) {
                // This should only happen if the query string syntax is invalid because someone edited or mistyped it.
                break;
            }

            $facetDefinition = $this->facetDefinitions[$facetId];
            $facetName = htmlspecialchars($this->facetDefinitions[$facetId]['name']);
            $appliedFilters .= '<div class="elasticsearch-facet-name">' . $facetName . '</div>';
            $appliedFilters .= '<ul>';
            $rootValue = '';
            $class = '';

            foreach ($facetValues as $index => $facetValue)
            {
                $level = $index == 0 ? 'root' : 'leaf';

                $emitLink = true;
                $linkText = $facetValue;

                if ($facetDefinition['is_hierarchy'] && $facetDefinition['show_root']) {
                    $isLeaf = $level == 'leaf';

                    // Only emit the [x] link for a removable facet. That's either a root by itself or a leaf.
                    $emitLink = count($facetValues) == 1 || $isLeaf;
                    if ($isLeaf) {
                        $class = " class='elasticsearch-facet-level2'";

                        // Remove the root value from the leaf text.
                        $prefixLen = strlen($rootValue) + strlen(', ') - strlen('_');
                        $linkText = substr($facetValue, $prefixLen);
                    } else {
                        $rootValue = $facetValue;

                        // Remove the leading underscore that appears as the first character of a root facet value.
                        $linkText = substr($linkText, 1);
                    }
                }

                $appliedFacets[$facetId][$level] = $linkText;
                $appliedFacets[$facetId]['facet_value'] = $facetValue;
                $resetLink = $this->createRemoveFacetLink($queryString, $facetId, $facetValue);
                $appliedFilters .= '<li>';
                $appliedFilters .= "<i$class>$linkText</i>";
                if ($emitLink)
                {
                    $appliedFilters .= '<a href="' . $findUrl . '?' . $resetLink . '"> [&#10006;]</a>';
                }
                $appliedFilters .= '</li>';
            }

            $appliedFilters .= '</ul>';
        }

        return $appliedFilters;
    }

    public function emitHtmlForFilters()
    {

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
        if (!array_key_exists($elasticsearchFieldName, $this->facetDefinitions))
        {
            // This element does not have a facet associated with it.
            return array();
        }

        $values = array();

        // Get the value for each of the element's texts.
        foreach ($fieldTexts as $fieldText)
        {
            $text = $fieldText['text'];

            $facetDefinition = $this->facetDefinitions[$elasticsearchFieldName];

            if ($facetDefinition['is_hierarchy'])
            {
                $getRoot = $this->facetDefinitions[$elasticsearchFieldName]['show_root'];
                $hierarchy = $this->getFacetHierarchyParts($text, $getRoot);

                if ($getRoot)
                {
                    // Prepend an underscore onto the root to distinguish it from a leaf value.
                    $values[] = '_' . $hierarchy['root'];
                }

                $values[] = $hierarchy['leaf'];
            }
            else if ($this->facetDefinitions[$elasticsearchFieldName]['is_date'])
            {
                $values[] = $this->getFacetValueForDate($text);
            }
        }

        return $values;
    }

    protected function getFacetHierarchyParts($text, $getRoot)
    {
        // Normalize the text so that hierarchy parts are separated by commas with no spaces.
        // This regex replaces a comma followed by one or more spaces with just a comma.
        $text = trim(preg_replace('/,\s+/', ',', $text));
        $parts = explode(',', $text);
        $partsCount = count($parts);

        // Extract the root and leaf values. In this case, the leaf is the entire string minus any
        // any values in between the root and the actual leaf. See examples below.
        $root = $parts[0];

        $last = $partsCount - 1;
        $lastPart = $parts[$last];

        if ($getRoot)
        {
            $leaf = $root;

            if ($partsCount == 2)
            {
                // Example: 'Image,Photograph' => 'Image, Photograph'
                $leaf .= ", $parts[1]";
            }
            else if ($partsCount == 3)
            {
                // Example: 'Image,Photograph,Print' => 'Image, Photograph, Print'
                $leaf .= ", $parts[1], $parts[2]";
            }
            else if ($partsCount > 3)
            {
                // Example: 'Image,Photograph,Negative,Glass Plate' => 'Image, Photograph, Glass Plate'
                $leaf .= ", $parts[1], $lastPart";
            }
        }
        else
        {
            $leaf = $lastPart;
        }

        return array('root' => $root, 'leaf' => $leaf);
    }
}