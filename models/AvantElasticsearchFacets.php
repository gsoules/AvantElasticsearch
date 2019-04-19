<?php

class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $facetDefinitions = array();

    public function __construct()
    {
        parent::__construct();
        $this->defineFacets();
    }

    public function addFacetArgToQueryString($queryString, $facetToAddId, $facetToAddValue, $isRoot)
    {
        $args = explode('&', $queryString);
        $addFacet = true;

        foreach ($args as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $kind = $isRoot ? 'root' : 'facet';
            $facetArg = "{$kind}_{$facetToAddId}[]";

            $target = "$facetArg=$facetToAddValue";
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

    protected function checkIfFacetAlreadyApplied($appliedFacets, $facetToAddId, $kind, $bucketValue)
    {
        foreach ($appliedFacets[$kind] as $appliedFacetId => $appliedFacetValues)
        {
            if ($facetToAddId == $appliedFacetId)
            {
                foreach ($appliedFacetValues as $appliedFacetValue)
                {
                    if ($appliedFacetValue == $bucketValue)
                    {
                        return true;
                    }
                }
            }
        }
        return false;
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

    public function emitHtmlForFilters($aggregations, $query, $findUrl)
    {
        $appliedFacets = $this->getAppliedFacetsFromQueryString($query);
        $queryString = $this->createQueryStringWithFacets($query);
        $html = '';

        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            $isRoot = $facetDefinition['is_hierarchy'] && $facetDefinition['show_root'];
            $buckets = $aggregations[$facetId]['buckets'];

            if (count($buckets) == 0 || $facetDefinition['hidden'])
            {
                // Don't display empty buckets or hidden facets.
                // TO-DO: Hide empty buckes and hidden facets by uncommenting continue
                //continue;
            }

            $filters = '';
            $buckets = $aggregations[$facetId]['buckets'];

            foreach ($buckets as $bucket)
            {
                $filter = $this->emitHtmlLinkForFacetFilter($findUrl, $bucket, $queryString, $appliedFacets, $facetId, $isRoot);
                $class = " class='elasticsearch-facet-level2'";
                $filters .= "<li$class>$filter</li>";
            }

            $sectionName = $facetDefinition['name'];
            $html .= '<div class="elasticsearch-facet-name">' . $sectionName . '</div>';
            $html .= "<ul>$filters</ul>";
        }

        return $html;
    }

    protected function emitHtmlLinkForFacetFilter($findUrl, $bucket, $queryString, $appliedFacets, $facetToAddId, $isRoot)
    {
        // Create a link that the user can click to apply this facet. The applied facets are structured as follows.
        $bucketValue = $bucket['key'];
        $updatedQueryString = $queryString;

        // The nested loops below add an argument to the query string for each facet that is already applied.
        // - At the top level there are two facet kinds: 'root' and 'facet'
        // - For each kind, there can be zero or more facet types e.g. subject, place.
        // - For each type, there can be one or more values.
        // Note that the user interface might not allow, for example, two root level facets of the same
        // type to be selected as filters, but this logic handles any combination of applied facets.
        foreach ($appliedFacets as $kind => $appliedFacet)
        {
            foreach ($appliedFacet as $appliedFacetId => $appliedFacetValues)
            {
                foreach ($appliedFacetValues as $appliedFacetValue)
                {
                    $updatedQueryString = $this->addFacetArgToQueryString($updatedQueryString, $appliedFacetId, $appliedFacetValue, $kind == 'root');
                }
            }
        }

        // Add an argument to the query string for the facet being added. The resulting link, when clicked,
        // will filter on all the previously applied facets plus the one now being added.
        $updatedQueryString = $this->addFacetArgToQueryString($updatedQueryString, $facetToAddId, $bucketValue, $isRoot);

        $facetUrl = $findUrl . '?' . $updatedQueryString;
        $count = ' (' . $bucket['doc_count'] . ')';

        $kind = $isRoot ? 'root' : 'facet';

        $applied = $this->checkIfFacetAlreadyApplied($appliedFacets, $facetToAddId, $kind, $bucketValue);
        if ($applied)
        {
            $filter = $bucketValue . ' X';
        }
        else
        {
            $filter = '<a href="' . $facetUrl . '">' . $bucketValue . '</a>' . $count;
        }

        return $filter;
    }

    public function getAppliedFacetsFromQueryString($query)
    {
        $appliedFacets = array('root' => array(), 'facet' => array());

        $queryStringRoots = isset($query['root']) ? $query['root'] : array();
        $queryStringFacets = isset($query['facet']) ? $query['facet'] : array();

        foreach ($queryStringRoots as $facetId => $facetValues)
        {
            foreach ($facetValues as $facetValue)
            {
                $appliedFacets['root'][$facetId][] = $facetValue;
            }
        }

        foreach ($queryStringFacets as $facetId => $facetValues)
        {
            foreach ($facetValues as $facetValue)
            {
                $appliedFacets['facet'][$facetId][] = $facetValue;
            }
        }

        return $appliedFacets;
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