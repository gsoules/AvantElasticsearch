<?php

// Root refers to the top value in a hierarchy facet.
// Leaf refers to either the elided leaf value in a hierarchy facet or simply the value in a non-hierarchy facet.
define('FACET_KIND_ROOT', 'root');
define('FACET_KIND_LEAF', 'leaf');

class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $facetDefinitions = array();
    protected $facetsTable = array();
    protected $findUrl;
    protected $appliedFacets = array();
    protected $queryStringWithApplieFacets;

    public function __construct()
    {
        parent::__construct();
        $this->defineFacets();
    }

    protected function checkIfFacetAlreadyApplied($appliedFacets, $facetToCheckKind, $facetToCheckId, $facetToCheckValue)
    {
        if ($facetToCheckKind == FACET_KIND_ROOT)
        {
            $applied = $this->checkIfFacetKindAlreadyApplied(FACET_KIND_ROOT, $appliedFacets, $facetToCheckId, $facetToCheckValue);
            if ($applied)
            {
                return true;
            }
        }

        $applied = $this->checkIfFacetKindAlreadyApplied(FACET_KIND_LEAF, $appliedFacets, $facetToCheckId, $facetToCheckValue);

        return $applied;
    }

    protected function checkIfFacetKindAlreadyApplied($appliedFacetsToCheckKind, $appliedFacets, $facetToCheckId, $facetToCheckValue)
    {
        $appliedFacetsToCheck = $appliedFacets[$appliedFacetsToCheckKind];
        if (empty($appliedFacetsToCheck))
        {
            return false;
        }

        foreach ($appliedFacetsToCheck as $appliedFacetId => $appliedFacetValues)
        {
            if ($facetToCheckId != $appliedFacetId)
            {
                continue;
            }

            foreach ($appliedFacetValues as $appliedFacetValue)
            {
                if ($appliedFacetValue == $facetToCheckValue)
                {
                    return true;
                }

                // This code is reached when a root facet value to check does not match an applied root facet value.
                // Check now to see if the root facet value to check is the root of an applied leaf facet value.
                // For example, if the root facet value to check is 'Image', see if it's the root of an applied leaf
                // facet value of the same type like 'Image,Art,Drawing'. If yes, the root facet is implicitly applied.
                $facetToCheckValueIsRootOfAppliedFacetValue = strpos($appliedFacetValue, $facetToCheckValue . ',') === 0;
                if ($facetToCheckValueIsRootOfAppliedFacetValue)
                {
                    return true;
                }
            }
        }

        return false;
    }

    protected function checkIfRootLeafAlreadyApplied($appliedFacets, $facetToCheckId, $facetToCheckValue)
    {
        $applied = $this->checkIfFacetAlreadyApplied($appliedFacets, FACET_KIND_LEAF, $facetToCheckId, $facetToCheckValue);
        return $applied;
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

        $this->queryStringWithApplieFacets = $updatedQueryString;
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

    protected function createFacetsTable($aggregations)
    {
        // Create a table containing the aggregation data returned from Elasticsearch for the search results.
        // Each aggregate contains any array of buckets with each bucket containing a unique value and a count to
        // indicate how many of the search results have that value. If the facet is a hierarchy, the bucket's value
        // is the hierarchy's root name plus an array of sub-aggregate data for that root's leaf values. Each
        // sub-aggregate contains its own array of buckets with each bucket containing a leaf value and count to
        // indicate how many of the search results contain the leaf value for that root. For non-hierarchy facets
        // like 'date' there are no sub-aggregates. In this code, both the leaf values for hierarchy facets and the
        // top level values for non-hierarchy facets are referred to as leafs. Only the root value of hierarchy
        // facets are referred to as roots.

        $table = array();

        foreach ($aggregations as $facetId=> $aggregation)
        {
            $buckets = $aggregation['buckets'];
            if (empty($buckets))
                continue;

            foreach ($buckets as $i => $bucket)
            {
                $facetName = $bucket['key'];
                $table[$facetId][$i]['id'] = $facetId;
                $table[$facetId][$i]['name'] = $facetName;
                $table[$facetId][$i]['count'] = $bucket['doc_count'];
                $table[$facetId][$i]['action'] = 'add';

                $leafBuckets = isset($bucket['leafs']['buckets']) ? $bucket['leafs']['buckets'] : array();

                if (empty($leafBuckets))
                    continue;

                foreach ($leafBuckets as $j => $leafBucket)
                {
                    $leafFacetName = $this->stripRootFromLeafName($leafBucket['key']);
                    $table[$facetId][$i]['leafs'][$j]['name'] = $leafFacetName;
                    $table[$facetId][$i]['leafs'][$j]['count'] = $leafBucket['doc_count'];
                    $table[$facetId][$i]['leafs'][$j]['action'] = 'hide';
                }
            }
        }
        $this->facetsTable = $table;
    }

    protected function setFacetsTableActions()
    {
        $appliedRootFacets = $this->appliedFacets['root'];
        $appliedLeafFacets = $this->appliedFacets['leaf'];

        foreach ($appliedRootFacets as $appliedRootFacet)
        {
            continue;
        }
    }

    protected function emitHtmlForFacetEntry($entry, $level, $isRoot = false)
    {
        $className = "facet-entry-$level";
        if ($level == 1)
        {
            $className .= $isRoot ? '-root' : '-leaf';
        }
        $class = " class='$className'";
        $entry = str_replace(',', ', ', $entry);
        return "<li$class>$entry</li>";
    }

    protected function emitFacetEntryActionHtml($facetTableEntry, $isRoot)
    {
        $action = $facetTableEntry['action'];
        $facetName = $facetTableEntry['name'];

        if ($action == 'add')
        {
            $actionHtml = $this->emitHtmlLinkForAddFilter($facetTableEntry['id'], $facetName, $isRoot);
        }
        else if ($action == 'remove')
        {
            $actionHtml = $facetName . ' [-]';
        }
        else
        {
            $actionHtml = $facetName;
        }

        return $actionHtml;
    }

    protected function emitHtmlForFacetSectionEntries($facetId, $facetDefinition)
    {
        $html = '';
        $sectionHasAppliedFacet = false;

        // Emit the entries for this facet.
        $entries = $this->facetsTable[$facetId];
        foreach ($entries as $entry)
        {
            $isRoot = $facetDefinition['is_hierarchy'] && $facetDefinition['show_root'];
            $action = $this->emitFacetEntryActionHtml($entry, $isRoot);
            $html .= $this->emitHtmlForFacetEntry($action, 1, $isRoot);

            // Emit the leaf entries for this facet entry.
            if (isset($entry['leafs']))
            {
                $leafEntries = $entry['leafs'];
                foreach ($leafEntries as $leafEntry)
                {
                    if ($leafEntry['action'] == 'hide')
                        continue;
                    $leafAction = $this->emitFacetEntryActionHtml($leafEntry);
                    $html .= $this->emitHtmlForFacetEntry($leafAction, 2);
                }
            }
        }

        // Emit the section header for this facet.
        $className = 'facet-section' . ($sectionHasAppliedFacet ? '-applied' : '');
        $sectionHeader = "<div class='$className'>{$facetDefinition['name']}</div>";
        return "$sectionHeader<ul>$html</ul>";
    }

    protected function emitHtmlForFacetSections()
    {
        $html = '';

        foreach ($this->facetDefinitions as $facetId => $facetDefinition)
        {
            if (!isset($this->facetsTable[$facetId]))
            {
                // The search results contain no values for this facet.
                continue;
            }

            if ($facetDefinition['hidden'])
            {
                // This facet is for future use and not currently being displayed.
                continue;
            }

            $html .= $this->emitHtmlForFacetSectionEntries($facetId, $facetDefinition);
        }

        return $html;
    }

    public function emitHtmlForFacetsSidebar($aggregations, $query, $findUrl)
    {
        $this->findUrl = $findUrl;

        // Get the facets that were applied to the search request.
        $this->extractAppliedFacetsFromSearchRequest($query);

        // Create a table of all the facet values that Elasticsearch returned in the search results.
        $this->createFacetsTable($aggregations);

        // Create a query string containing the query's search terms e.g. 'Bar Harbor' plus all facets that are
        // already applied e.g. type:images and date:1900's. An add-link is this same query string with an addition
        // argument for the facet to be added. A remove-X link is the query string minus the argument for the
        // facet to be removed.
        $this->createQueryStringWithFacets($query);

        // Indicate how each facet should appear in the sidebar: add-link, remove-X, disabled, hidden.
        $this->setFacetsTableActions();

        // Display all the facet entries.
        $html = $this->emitHtmlForFacetSections();

        return $html;
    }

    protected function emitHtmlLinksForFacetFilter($bucket, $queryString, $appliedFacets, $facetId, $findUrl, $isRoot)
    {
        $facetValue = $bucket['key'];
        $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;

        // Determine whether this facet has already been applied.
        $applied = $this->checkIfFacetAlreadyApplied($appliedFacets, $kind, $facetId, $facetValue);

        if ($applied)
        {
            $filters = $this->emitHtmlLinksForFacetFilterApplied($bucket, $queryString, $appliedFacets, $facetId, $findUrl, $isRoot, $facetValue);
        }
        else
        {
            $filters = '';//$this->emitHtmlLinkForAddFilter($bucket, $queryString, $appliedFacets, $facetId, $findUrl, $isRoot, $facetValue);
        }

        return $filters;
    }

    protected function emitHtmlLinksForFacetFilterApplied($bucket, $queryString, $appliedFacets, $facetId, $findUrl, $isRoot, $facetValue)
    {
        if ($isRoot && $this->checkIfRootLeafAlreadyApplied($appliedFacets, $facetId, $facetValue))
        {
            // Only show the root value without a remove 'X'. This way a user can't remove the root
            // filter without first removing the root's leaf filter.
            $class = " class='elasticsearch-facet-level2'";
            $filter = $facetValue;
        }
        else
        {
            // Create the link that allows the user to remove this filter.
            $class = " class='elasticsearch-facet-level3'";
            $filter = $this->emitHtmlLinkForRemoveFilter($queryString, $facetId, $facetValue, $findUrl, $isRoot);
        }

        $filters = "<li$class>$filter</li>";

        if ($isRoot)
        {
            // Emit this facet's leafs by calling this method recursively.
            foreach ($bucket['leafs']['buckets'] as $leafBucket)
            {
                $filters .= $this->emitHtmlLinksForFacetFilter($leafBucket, $queryString, $appliedFacets, $facetId, $findUrl, false);
            }
        }

        return $filters;
    }

    protected function emitHtmlLinkForAddFilter($facetId, $facetName, $isRoot)
    {
        // Add an argument to the query string for each facet that is already applied.
        // The applied facets are structured as follows:
        // - At the top level there are two facet kinds: FACET_KIND_ROOT and FACET_KIND_LEAF
        // - For each kind, there can be zero or more facet Ids e.g. 'subject', 'type', 'place'.
        // - For each Id, there can be one or more values e.g. two subjects.
        //
        // Note that the user interface might not allow, for example, two root level facets of the same
        // type to be selected as filters, but this logic handles any combination of applied facets.
        //
        $updatedQueryString = $this->queryStringWithApplieFacets;
        foreach ($this->appliedFacets as $kind => $appliedFacet)
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
        $updatedQueryString = $this->editQueryStringToAddFacetArg($updatedQueryString, $facetId, $facetName, $isRoot);

        // Create the link that the user can click to apply this facet plus all the already applied facets.
        // In the link text, add a space after each comma for readability.
        $facetUrl = $this->findUrl . '?' . $updatedQueryString;
        $linkText = str_replace(',', ', ', $facetName);

        $link = '<a href="' . $facetUrl . '">' . $linkText . '</a>';
        return $link;
    }

    protected function emitHtmlLinkForRemoveFilter($queryString, $facetToRemoveId, $facetToRemoveValue, $findUrl, $isRoot)
    {
        $updatedQueryString = $this->editQueryStringToRemoveFacetArg($queryString, $facetToRemoveId, $facetToRemoveValue, $isRoot);

        // Create the link that the user can click to remove this facet, but leave all the other applied facets.
        // In the facet text, add a space after each comma for readability.
        $facetUrl = $findUrl . '?' . $updatedQueryString;
        $facetText = str_replace(',', ', ', $facetToRemoveValue);
        $filter = $facetText . ' <a href="' . $facetUrl . '">' . '&#10006;' . '</a>';
        return $filter;
    }

    public function extractAppliedFacetsFromSearchRequest($query)
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

        $this->appliedFacets = $appliedFacets;
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

    protected function stripRootFromLeafName($leafName)
    {
        $index = strpos($leafName, ',');
        if ($index !== false)
        {
            $leafName = substr($leafName, $index + 1);
        }
        return $leafName;
    }
}