<?php

// Root refers to the top value in a hierarchy facet.
// Leaf refers to either the elided leaf value in a hierarchy facet or simply the value in a non-hierarchy facet.
define('FACET_KIND_ROOT', 'root');
define('FACET_KIND_LEAF', 'leaf');

class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $facetDefinitions = array();

    public function __construct()
    {
        parent::__construct();
        $this->defineFacets();
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
                                'field' => "facet.$facetId.leaf",
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

    protected function createFacetFilter($filters, $roots, $leafs, $facetDefinition)
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

        if (isset($leafs[$facetId]))
        {
            $term = "facet.$facetId";

            if ($facetDefinition['is_hierarchy'] && $facetDefinition['show_root'])
            {
                $term .= ".leaf";
            }

            $values = $leafs[$facetId];
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$term => $value]];
            }
        }

        return $filters;
    }

    public function createQueryStringWithFacets($query)
    {
        // Get the search terms plus the root and leaf facets specified in the query.
        $terms = isset($query['query']) ? $query['query'] : '';
        $facets = isset($query[FACET_KIND_LEAF]) ? $query[FACET_KIND_LEAF] : array();
        $roots = isset($query[FACET_KIND_ROOT]) ? $query[FACET_KIND_ROOT] : array();

        // Create a query string that contains the terms and args.
        $queryString = "query=".urlencode($terms);
        $updatedQueryString = $queryString;
        $updatedQueryString .= $this->createQueryStringArgsForFacets($roots, true);
        $updatedQueryString .= $this->createQueryStringArgsForFacets($facets, false);

        return $updatedQueryString;
    }

    protected function createQueryStringArgsForFacets($facets, $isRoot)
    {
        $queryStringArgs = '';

        // Create a query string arg for each facet.
        foreach ($facets as $facetName => $facetValues)
        {
            foreach ($facetValues as $facetValue)
            {
                $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;
                $queryStringArgs .= '&'.urlencode("{$kind}_{$facetName}[]") . '=' . urlencode($facetValue);
            }
        }

        return $queryStringArgs;
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

    public function editQueryStringToAddFacetArg($queryString, $facetToAddId, $facetToAddValue, $isRoot)
    {
        $args = explode('&', $queryString);
        $addFacet = true;

        foreach ($args as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;
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

    public function editQueryStringToRemoveFacetArg($queryString, $facetToRemoveId, $facetToRemoveValue, $isRoot)
    {
        $beforeArgs = explode('&', $queryString);
        $afterArgs = array();

        foreach ($beforeArgs as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;
            $facetArg = "{$kind}_{$facetToRemoveId}[]";

            $target = "$facetArg=$facetToRemoveValue";
            $argContainsTarget = $target == $arg;

            if (!$argContainsTarget)
            {
                // Keep this arg since it not the one to be removed.
                $afterArgs[] = $arg;
            }
        }
        return implode('&', $afterArgs);
    }

    public function emitHtmlForFilters($aggregations, $query, $findUrl)
    {
        // Create a list of all the filters that a user can add by clicking its link or remove by clicking its 'X'.

        // Start by creating a query string containing the search terms plus all facets that are already applied.
        $appliedFacets = $this->getAppliedFacetsFromQueryString($query);
        $queryString = $this->createQueryStringWithFacets($query);
        $html = '';

        // Loop over all facet kinds and emit add/remove links for facet values that exist for the current search results.
        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            // Get all the values for this facet Id.
            $buckets = $aggregations[$facetId]['buckets'];
            if (count($buckets) == 0 || $facetDefinition['hidden'])
            {
                // Don't display empty buckets or hidden facets.
                // TO-DO: Hide empty buckes and hidden facets by uncommenting continue
                //continue;
            }

            // For each value, emit the add or remove link.
            $filters = '';
            $isRoot = $facetDefinition['is_hierarchy'] && $facetDefinition['show_root'];
            foreach ($buckets as $bucket)
            {
                $filter = $this->emitHtmlLinkForFacetFilter($bucket, $queryString, $appliedFacets, $facetId, $findUrl, $isRoot);
                $class = " class='elasticsearch-facet-level2'";
                $filters .= "<li$class>$filter</li>";
            }

            // Emit the section name for this facet Id.
            $sectionName = $facetDefinition['name'];
            $html .= '<div class="elasticsearch-facet-name">' . $sectionName . '</div>';
            $html .= "<ul>$filters</ul>";
        }

        return $html;
    }

    protected function emitHtmlLinkForFacetFilter($bucket, $queryString, $appliedFacets, $facetToAddId, $findUrl, $isRoot)
    {
        $bucketValue = $bucket['key'];
        $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;

        // Determine whether this facet has already been applied.
        $applied = $this->checkIfFacetAlreadyApplied($appliedFacets, $facetToAddId, $kind, $bucketValue);

        if ($applied)
        {
            // Create the link that allows the user to remove this filter.
            $filter = $this->emitHtmlLinkForRemoveFilter($queryString, $facetToAddId, $bucketValue, $findUrl, $isRoot);
            $filters = $filter;

            if ($isRoot)
            {
                // Emit this facet's leafs by calling this method recursively.
                foreach ($bucket['leafs']['buckets'] as $leafBucket)
                {
//                    $leafValue = $leafBucket['key'];
//                    $leafValueWithoutRoot = substr($leafValue, strlen($bucketValue) + strlen(', '));
//                    $leafBucket['key'] = $leafValueWithoutRoot;
                    $filter = $this->emitHtmlLinkForFacetFilter($leafBucket, $queryString, $appliedFacets, $facetToAddId, $findUrl, false);
                    $class = " class='elasticsearch-facet-level3'";
                    $filters .= "<li$class>$filter</li>";
                }
            }

            return $filters;
        }

        // Add an argument to the query string for each facet that is already applied.
        // The applied facets are structured as follows:
        // - At the top level there are two facet kinds: FACET_KIND_ROOT and FACET_KIND_LEAF
        // - For each kind, there can be zero or more facet Ids e.g. 'subject', 'type', 'place'.
        // - For each Id, there can be one or more values e.g. two subjects.
        //
        // Note that the user interface might not allow, for example, two root level facets of the same
        // type to be selected as filters, but this logic handles any combination of applied facets.
        //
        $updatedQueryString = $queryString;
        foreach ($appliedFacets as $kind => $appliedFacet)
        {
            foreach ($appliedFacet as $appliedFacetId => $appliedFacetValues)
            {
                foreach ($appliedFacetValues as $appliedFacetValue)
                {
                    $updatedQueryString = $this->editQueryStringToAddFacetArg($updatedQueryString, $appliedFacetId, $appliedFacetValue, $kind == FACET_KIND_ROOT);
                }
            }
        }

        // Add an argument to the query string for the facet now being added.
        $updatedQueryString = $this->editQueryStringToAddFacetArg($updatedQueryString, $facetToAddId, $bucketValue, $isRoot);

        // Create the link that the user can click to apply this facet plus all the already applied facets.
        $facetUrl = $findUrl . '?' . $updatedQueryString;
        $count = ' (' . $bucket['doc_count'] . ')';
        $filter = '<a href="' . $facetUrl . '">' . $bucketValue . '</a>' . $count;
        return $filter;
    }

    protected function emitHtmlLinkForRemoveFilter($queryString, $facetToRemoveId, $facetToRemoveValue, $findUrl, $isRoot)
    {
        $updatedQueryString = $this->editQueryStringToRemoveFacetArg($queryString, $facetToRemoveId, $facetToRemoveValue, $isRoot);

        // Create the link that the user can click to remove this facet, but leave all the other applied facets.
        $facetUrl = $findUrl . '?' . $updatedQueryString;
        $filter = $facetToRemoveValue . ' <a href="' . $facetUrl . '">' . '&#10006;' . '</a>';
        return $filter;
    }

    public function getAppliedFacetsFromQueryString($query)
    {
        $appliedFacets = array(FACET_KIND_ROOT => array(), FACET_KIND_LEAF => array());

        $queryStringRoots = isset($query[FACET_KIND_ROOT]) ? $query[FACET_KIND_ROOT] : array();
        $queryStringFacets = isset($query[FACET_KIND_LEAF]) ? $query[FACET_KIND_LEAF] : array();

        foreach ($queryStringRoots as $facetId => $facetValues)
        {
            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[FACET_KIND_ROOT][$facetId][] = $facetValue;
            }
        }

        foreach ($queryStringFacets as $facetId => $facetValues)
        {
            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[FACET_KIND_LEAF][$facetId][] = $facetValue;
            }
        }

        return $appliedFacets;
    }

    public function getFacetDefinitions()
    {
        return $this->facetDefinitions;
    }

    public function getFacetFiltersForElasticsearchQuery($roots, $leafs)
    {
        $filters = array();

        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            $filters = $this->createFacetFilter($filters, $roots, $leafs, $facetDefinition);
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
                    $values[] = array(FACET_KIND_ROOT => $hierarchy[FACET_KIND_ROOT], FACET_KIND_LEAF => $hierarchy[FACET_KIND_LEAF]);
                }
                else
                {
                    $values[] = $hierarchy[FACET_KIND_LEAF];
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
                // Example: 'Image,Photograph' => 'Image,Photograph'
                $leaf .= ",$parts[1]";
            }
            else if ($partsCount == 3)
            {
                // Example: 'Image,Photograph,Print' => 'Image,Photograph,Print'
                $leaf .= ",$parts[1],$parts[2]";
            }
            else if ($partsCount > 3)
            {
                // Example: 'Image,Photograph,Negative,Glass Plate' => 'Image,Photograph,Glass Plate'
                $leaf .= ",$parts[1],$lastPart";
            }
        }
        else
        {
            $leaf = $lastPart;
        }

        return array(FACET_KIND_ROOT => $root, FACET_KIND_LEAF => $leaf);
    }
}