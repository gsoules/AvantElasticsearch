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
            'id' => $id,
            'name' => $name,
            'is_date' => false,
            'is_hierarchy' => $isHierarchy,
            'show_root' => true,
            'multi_value' => false,
            'hidden' => false);

        $this->facetDefinitions[$id] = $definition;
    }

    public function createAddFacetLink($queryString, $facetToAdd, $facetValue, $isRoot)
    {
        $args = explode('&', $queryString);
        $addFacet = true;

        foreach ($args as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $kind = $isRoot ? 'root' : 'facet';
            $facetArg = "{$kind}_{$facetToAdd}[]";

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
        foreach ($this->facetDefinitions as $facetId => $definition)
        {
            if ($definition['is_hierarchy'] && $definition['show_root'])
            {
                // Build a sub-aggregation to get buckets of root values, each containing buckets of leaf values.
                $terms[$facetId] = [
                    'terms' => [
                        'field' => "facet.$facetId.root",
                        'size' => 10,
                        'order' => ['_key' => 'asc']
                    ],
                    'aggregations' => [
                        'leafs' => [
                            'terms' => [
                                'field' => "facet.$facetId.root",
                                'size' => 1000,
                                'order' => ['_key' => 'asc']
                            ]
                        ]
                    ]
                ];
            }
            else
            {
                // Build a simple aggregation to return buckets of values.
                $terms[$facetId] = [
                    'terms' => [
                        'field' => "facet.$facetId",
                        'size' => 1000,
                        'order' => ['_key' => 'asc']
                    ]
                ];
            }
        }

        // Convert the array into a nested object for the aggregation as required by Elasticsearch.
        $aggregations = (object)json_decode(json_encode($terms));

        return $aggregations;
    }

    protected function createFacetFilter($filters, $roots, $facets, $facetDefinition)
    {
        // Create a separate term filter for each value so that the filters are ANDed
        // as opposed to using a single 'terms' filter with multiple values that are ORed.

        $facetId = $facetDefinition['id'];

        if (isset($roots[$facetId]))
        {
            $term = "facet.$facetId.root";

            $values = $roots[$facetId];
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$term => $value]];
            }
        }

        if (isset($facets[$facetId]))
        {
            $term = "facet.$facetId";

            if ($facetDefinition['is_hierarchy'] && $facetDefinition['show_root'])
            {
                $term .= ".root";
            }

            $values = $facets[$facetId];
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$term => $value]];
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

    protected function defineFacets()
    {
        // The order is the order in which facet names appear in the Filters section on the search results page.
        $this->createFacet('subject', 'Subjects', true);
        $this->facetDefinitions['subject']['multi_value'] = true;

        $this->createFacet('type', 'Item Type', true);

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
        return $appliedFilters;

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

    public function emitHtmlForFilters($aggregations, $appliedFacets, $query, $findUrl)
    {
        $queryString = $this->createQueryStringWithFacets($query);

        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            $isRoot = $facetDefinition['is_hierarchy'] && $facetDefinition['show_root'];

            $buckets = $aggregations[$facetId]['buckets'];

            if (count($buckets) == 0 || $facetDefinition['hidden'])
            {
                // Don't display empty buckets or hidden facets.
                continue;
            }

            $filters = '';
            $buckets = $aggregations[$facetId]['buckets'];

            foreach ($buckets as $bucket)
            {
                $bucketValue = $bucket['key'];

                // Create a link that the user can click to apply this facet.
                $count = ' (' . $bucket['doc_count'] . ')';
                $filterLink = $this->createAddFacetLink($queryString, $facetId, $bucketValue, $isRoot);
                $facetUrl = $findUrl . '?' . $filterLink;
                $filter = '<a href="' . $facetUrl . '">' . $bucketValue . '</a>' . $count;

                // Indent the filter link text
                $class = " class='elasticsearch-facet-level2'";
                $filters .= "<li$class>$filter</li>";
            }

            // Indent the filter link text
/*            $class = " class='elasticsearch-facet-level2'";
            $filters .= "<li$class>$filter</li>";

            $class = '';

                if ($facetDefinition['is_hierarchy'] && $facetDefinition['show_root'])
                {
                    if (isset($appliedFacets[$facetId]))
                    {
                        // This facet has been applied. Show it's leaf values indented.
                        if ($facetDefinition['show_root'])
                        {
                            if ($facetDefinition['multi_value'])
                            {
                                // Determine if this value is part of the same sub-hierarchy as the applied root facet.
                                $rootValue = $appliedFacets[$facetId]['root'];

                                if (strpos($bucketValue, $rootValue) === 0)
                                {
                                    // Remove the root from the leaf unless the root and leaf are the same.
                                    // That can happen when the the value has no leaf part.
                                    if (strcmp($rootValue, $filterLinkText) != 0)
                                    {
                                        $prefixLen = strlen($rootValue) + strlen(', ');
                                        $filterLinkText = substr($filterLinkText, $prefixLen);
                                    }
                                }
                                else
                                {
                                    // Not part of same sub-hierarchy.
                                    continue;
                                }
                            }

                            // Add some styling when leafs appear under roots.
                            $level = $isRoot ? '1' : '2';
                            $class = " class='elasticsearch-facet-level$level'";
                        }
                    }
                }

                // Determine if this bucket value has already been applied. If the bucket value is a
                // root, strip off the leading underscore before comparing to applied values.
                $applied = false;
                if (isset($appliedFacets[$facetId]))
                {
                    $values = $appliedFacets[$facetId];
                    if ($facetDefinition['is_hierarchy'])
                    {
                        if ($isRoot)
                        {
                            $value = $rootValue;
                        }
                        else
                        {
                            $rootValue = substr($rootValue, 1);
                            if ($bucketValue == $rootValue)
                            {
                                $value = $bucketValue;
                            }
                            else
                            {
                                $value = $appliedFacets[$facetId]['facet_value'];
                            }
                        }
                    }
                    else
                    {
                        $value = $bucketValue;
                    }
                    $applied = in_array($value, $values);
                }

                if ($applied)
                {
                    // Don't display a facet value that has already been applied.
                    continue;
                }
                else
                {
                    // Create a link that the user can click to apply this facet.
                    $count = ' (' . $bucket['doc_count'] . ')';
                    $filterLink = $this->createAddFacetLink($queryString, $facetId, $bucketValue);
                    $facetUrl = $findUrl . '?' . $filterLink;
                    $filter = '<a href="' . $facetUrl . '">' . $filterLinkText . '</a>' . $count;
                }

                // Indent the filter link text
                $class = " class='elasticsearch-facet-level2'";
                $filters .= "<li$class>$filter</li>";
            }*/

            if (!empty($filters))
            {
                // Determine the section name. When no facets are applied, it's the facet name, other wise the
                // root name of the applied facet.
                if (isset($appliedFacets[$facetId]))
                {
                    $sectionName = $appliedFacets[$facetId]['root'];
                }
                else
                {
                    $sectionName = $facetDefinition['name'];
                }

                echo '<div class="elasticsearch-facet-name">' . $sectionName . '</div>';
                echo "<ul>$filters</ul>";
            }
        }
    }

    public function getFacetDefinitions()
    {
        return $this->facetDefinitions;
    }

    public function getFacetFiltersForElasticsearchQuery($roots, $facets)
    {
        $filters = array();

        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            $filters = $this->createFacetFilter($filters, $roots, $facets, $facetDefinition);
        }

        return $filters;
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
                // Get the root and leaf for hierarchy values.
                $showRoot = $this->facetDefinitions[$elasticsearchFieldName]['show_root'];
                $hierarchy = $this->getFacetHierarchyParts($text, $showRoot);

                if ($showRoot)
                {
                    $values[] = array('root' => $hierarchy['root'], 'leaf' => $hierarchy['leaf']);
                }
                else
                {
                    $values[] = $hierarchy['leaf'];
                }
            }
            else
            {
                if ($this->facetDefinitions[$elasticsearchFieldName]['is_date'])
                {
                    $values[] = $this->getFacetValueForDate($text);
                }
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