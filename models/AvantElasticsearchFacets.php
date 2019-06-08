<?php

// Root refers to the top value in a hierarchy facet.
// Leaf refers to either the elided leaf value in a hierarchy facet or simply the value in a non-hierarchy facet.
define('FACET_KIND_ROOT', 'root');
define('FACET_KIND_LEAF', 'leaf');

class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $appliedFacets = array();
    protected $facetDefinitions = array();
    protected $facetsTable = array();
    protected $findUrl;
    protected $queryStringWithApplieFacets;
    protected $totalResults;

    public function __construct()
    {
        parent::__construct();
        $this->defineFacets();
    }

    protected function createFacet($group, $name, $isHierarchy = false, $isRootHierarchy = false)
    {
        $definition = array(
            'group' => $group,
            'name' => $name,
            'is_date' => false,
            'is_hierarchy' => $isHierarchy,
            'is_root_hierarchy' => $isRootHierarchy,
            'shared' => false,
            'sort' => true,
            'not_used' => false);

        $this->facetDefinitions[$group] = $definition;
    }

    protected function createFacetEntryHtml($facetTableEntry, $isRoot)
    {
        $action = $facetTableEntry['action'];
        $rootPath = $facetTableEntry['root_path'];
        $facetName = $facetTableEntry['name'];
        $facetText = str_replace(',', ', ', $facetName);

        if ($facetText == BLANK_FIELD_TEXT)
        {
            $facetText = BLANK_FIELD_SUBSTITUTE;
        }

        $html = '';

        if ($action == 'add')
        {
            $html = $this->emitHtmlLinkForAddFilter($facetTableEntry['group'], $facetText, $rootPath, $isRoot);
            $html .= " ({$facetTableEntry['count']})";
        }
        else
        {
            if ($action == 'remove')
            {
                $html = $this->emitHtmlLinkForRemoveFilter($facetTableEntry['group'], $facetText, $rootPath, $isRoot);
            }
            else
            {
                if ($action == 'none' || $action == 'dead')
                {
                    $html = $facetText;
                }
            }
        }

        return $html;
    }

    protected function createFacetFilter($filters, $roots, $leafs, $facetDefinition)
    {
        // Create a separate term filter for each value so that the filters are ANDed
        // as opposed to using a single 'terms' filter with multiple values that are ORed.

        $group = $facetDefinition['group'];

        if (isset($roots[$group]))
        {
            $term = "facet.$group.root";

            $values = $roots[$group];
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$term => $value]];
            }
        }

        if (isset($leafs[$group]))
        {
            $term = "facet.$group";

            if ($facetDefinition['is_root_hierarchy'])
            {
                $term .= ".leaf";
            }

            $values = $leafs[$group];
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$term => $value]];
            }
        }

        return $filters;
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

        foreach ($aggregations as $group => $aggregation)
        {
            $buckets = $aggregation['buckets'];
            if (empty($buckets))
            {
                continue;
            }

            foreach ($buckets as $i => $bucket)
            {
                $facetName = $bucket['key'];
                $table[$group][$i]['group'] = $group;
                $table[$group][$i]['root'] = '';
                $table[$group][$i]['name'] = $facetName;
                $table[$group][$i]['root_path'] = $facetName;
                $table[$group][$i]['count'] = $bucket['doc_count'];
                $table[$group][$i]['action'] = 'add';

                $leafBuckets = isset($bucket['leafs']['buckets']) ? $bucket['leafs']['buckets'] : array();

                if (empty($leafBuckets))
                {
                    continue;
                }

                foreach ($leafBuckets as $j => $leafBucket)
                {
                    $leafFacetRootPath = $leafBucket['key'];
                    $leafRootName = $this->getRootNameFromLeafName($leafFacetRootPath);
                    if ($leafRootName != $facetName)
                    {
                        continue;
                    }

                    $table[$group][$i]['leafs'][$j]['group'] = $group;
                    $table[$group][$i]['leafs'][$j]['root'] = $facetName;
                    $table[$group][$i]['leafs'][$j]['name'] = $this->stripRootFromLeafName($leafFacetRootPath);
                    $table[$group][$i]['leafs'][$j]['root_path'] = preg_replace('/,\s+/', ',', $leafFacetRootPath);
                    $table[$group][$i]['leafs'][$j]['count'] = $leafBucket['doc_count'];
                    $table[$group][$i]['leafs'][$j]['action'] = 'hide';
                }
            }
        }
        $this->facetsTable = $table;
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
                $queryStringArgs .= '&' . urlencode("{$kind}_{$facetName}[]") . '=' . urlencode($facetValue);
            }
        }

        return $queryStringArgs;
    }

    public function createQueryStringWithFacets($query)
    {
        $terms = '';
        $roots = array();
        $leafs = array();
        $otherArgs = array();

        foreach ($query as $arg => $value)
        {
            switch ($arg)
            {
                case 'query';
                    $terms = $value;
                    break;
                case 'root':
                    $roots = $value;
                    break;
                case 'leaf':
                    $leafs = $value;
                    break;
                default:
                    $otherArgs[$arg] = $value;
            }
        }

        // Create a query string that contains the terms and args.
        $queryString = "query=" . urlencode($terms);
        $updatedQueryString = $queryString;
        $updatedQueryString .= $this->createQueryStringArgsForFacets($roots, true);
        $updatedQueryString .= $this->createQueryStringArgsForFacets($leafs, false);

        foreach ($otherArgs as $arg => $value)
        {
            // Ignore any pagination arg from the query string that will be there if the user paged through the previous
            // search results. The query string created here will be used to produce new results and must not have the arg.
            if ($arg == 'page')
                continue;

            // Ignore any unexpected array values. This could happen if the user mistyped or edited the query string,
            // for example by specifying 'rot_type[]=Image' instead of 'root_type[]=Image'.
            if (is_array($value))
                continue;

            $updatedQueryString .= '&' . urlencode("$arg") . '=' . urlencode($value);
        }

        $this->queryStringWithApplieFacets = $updatedQueryString;
        return $updatedQueryString;
    }

    protected function defineFacets()
    {
        // The order below is the order in which facet names appear in the facets section on the search results page.

        $this->createFacet('subject', 'Subjects', true, true);
        $this->createFacet('type', 'Item Type', true, true);

        $this->createFacet('place', 'Places', true);

        $this->createFacet('date', 'Dates');
        $this->facetDefinitions['date']['is_date'] = true;

        $this->createFacet('contributor', 'Contributor');
        $this->facetDefinitions['contributor']['shared'] = true;
        $this->facetDefinitions['contributor']['sort'] = false;

        // Tags are fully supported, but for now don't show this facet since tags are not heavily/consistently used.
        $this->createFacet('tag', 'Tags');
        //$this->facetDefinitions['tag']['not_used'] = true;
    }

    public function editQueryStringToAddFacetArg($facetToAddGroup, $facetToAddValue, $isRoot)
    {
        $queryString = $this->queryStringWithApplieFacets;
        $args = explode('&', $queryString);

        // Create the arg to be added.
        $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;
        $facetArg = "{$kind}_{$facetToAddGroup}[]";
        $argToAdd = "$facetArg=$facetToAddValue";

        // Add the arg to be added to the new query string.
        $args[] = $argToAdd;

        $updatedQueryString = implode('&', $args);
        return $updatedQueryString;
    }

    public function editQueryStringToRemoveFacetArg($queryString, $facetToRemoveGroup, $facetToRemoveValue, $isRoot)
    {
        $args = explode('&', $queryString);

        foreach ($args as $index => $rawArg)
        {
            // Remove any %# encoding from the arg so it can be compared to the arg to be removed.
            $arg = urldecode($rawArg);

            // Construct the name/value of the arg to be remove.
            $kind = $isRoot ? FACET_KIND_ROOT : FACET_KIND_LEAF;
            $facetArg = "{$kind}_{$facetToRemoveGroup}[]";
            $argToRemove = "$facetArg=$facetToRemoveValue";

            if ($arg == $argToRemove)
            {
                unset($args[$index]);
                break;
            }
        }

        return implode('&', $args);
    }

    protected function emitHtmlForFacetEntry($entry, $facetDefinition, &$facetApplied)
    {
        // Emit the root and non-hierarchy leaf entries for this section.
        $html = '';

        if ($this->entryHasNoFilteringEffect($entry))
        {
            $entry['action'] = 'dead';
        }

        $isRoot = $facetDefinition['is_root_hierarchy'];
        $facetEntryHtml = $this->createFacetEntryHtml($entry, $isRoot);

        $facetApplied = $entry['action'] == 'remove' ? true : $facetApplied;
        $html .= $this->emitHtmlForFacetEntryListItem($facetEntryHtml, $entry['action'], 1, $isRoot, false);

        // Emit hierarchy  leaf entries for this facet entry.
        if (isset($entry['leafs']))
        {
            $leafEntries = $entry['leafs'];
            $leafEntryListItems = array();

            foreach ($leafEntries as $index => $leafEntry)
            {
                if ($leafEntry['action'] == 'hide')
                    continue;

                $facetApplied = $leafEntry['action'] == 'remove' ? true : $facetApplied;
                if ($this->entryHasNoFilteringEffect($leafEntry))
                {
                    $leafEntry['action'] = 'dead';
                }

                $isGrandchild = false;
                $name = $leafEntry['name'];
                $pos = strpos($leafEntry['name'], ',');
                if ($pos !== false)
                {
                    $isGrandchild = true;
                    $leafEntry['name'] = substr($name, $pos + 1);
                }

                // Record this leaf's entry, level, and action.
                $leafEntryListItems[$index]['entry'] = $leafEntry;
                $leafEntryListItems[$index]['level'] = $isGrandchild ? 3 : 2;
                $leafEntryListItems[$index]['action'] = $leafEntry['action'];
            }

            // Check for the special case where the user applied a child facet such as 'Image,Photograph'
            // and then applied a grandchild facet like 'Glass Plate'. In this case, don't show a remove-X
            // for the child facet, only for the grandchild.
            if (count($leafEntryListItems) == 2)
            {
                if (isset($leafEntryListItems[0]) && $leafEntryListItems[0]['level'] == 2 && $leafEntryListItems[1]['level'] == 3)
                {
                    if ($leafEntryListItems[0]['action'] == 'remove')
                    {
                        $leafEntryListItems[0]['action'] = 'none';
                        $leafEntryListItems[0]['entry']['action'] = 'none';
                    }
                }
            }

            // Emit the HTML for the leafs.
            foreach ($leafEntryListItems as $listItem)
            {
                $leafEntryHtml = $this->createFacetEntryHtml($listItem['entry'], false);
                $level = $listItem['level'];
                $action = $listItem['action'];
                $html .= $this->emitHtmlForFacetEntryListItem($leafEntryHtml, $action, $level, false);
            }
        }
        return $html;
    }

    protected function emitHtmlForFacetEntryListItem($html, $action, $level, $isRoot)
    {
        $className = "facet-entry-$level";

        if ($level == 1)
        {
            $className .= $isRoot ? '-root' : '-leaf';
        }

        if ($action == 'remove' || $action == 'none')
        {
            $className .= ' facet-entry-applied';
        }

        $class = " class='$className'";

        return "<li$class>$html</li>";
    }

    protected function emitHtmlForResetLink($query)
    {
        if (empty($this->appliedFacets['root']) && empty($this->appliedFacets['leaf']))
        {
            // No facets are applied and therefore no reset link is needed.
            return '';
        }

        // Create new query string args minus root or leaf facets, or the pagination arg if it exists.
        $otherArgs = '';
        foreach ($query as $argName => $argValue)
        {
            if ($argName == 'query' || $argName == 'root' || $argName == 'leaf' || $argName == 'page')
                continue;

            $otherArgs .= "&$argName=$argValue";
        }

        $terms = isset($query['query']) ? $query['query'] : '';
        $queryString = "query=" . urlencode($terms);
        $resetUrl = $this->findUrl . '?' . $queryString . $otherArgs;
        $resetLink = '<a href="' . $resetUrl . '" title="Reset" class="search-reset-link">' . '&#10006;' . '</a>';
        return $resetLink;
    }

    protected function emitHtmlForFacetSection($group, $facetDefinition)
    {
        $html = '';

        // This flag gets passed by reference to be set by emitHtmlForFacetEntry().
        $facetApplied = false;

        // Emit the entries for this facet.
        $entries = $this->facetsTable[$group];
        foreach ($entries as $facetIndex => $entry)
        {
            // Add the HTML for this entry to the HTML emitted so far.
            $html .= $this->emitHtmlForFacetEntry($entry, $facetDefinition, $facetApplied);
        }

        // Emit the section header for this facet.
        if (empty($html))
        {
            // No entries were emitted for this section. This happens when none of the entries have a count that is less
            // than the total number of search results and therefore can provide no additional filtering.
            return $html;
        }
        else
        {
            $className = 'facet-section' . ($facetApplied ? '-applied' : '');
            $sectionHeader = "<div class='$className'>{$facetDefinition['name']}</div>";
            return "$sectionHeader<ul id='facet-$group'>$html</ul>";
        }
    }

    protected function emitHtmlForFacetSections()
    {
        $html = '';

        foreach ($this->facetDefinitions as $group => $facetDefinition)
        {
            if (!isset($this->facetsTable[$group]))
            {
                // The search results contain no values for this facet.
                continue;
            }

            if ($facetDefinition['not_used'])
            {
                // This facet is not currently being displayed.
                continue;
            }

            $html .= $this->emitHtmlForFacetSection($group, $facetDefinition);
        }

        return $html;
    }

    public function emitHtmlForFacetsSidebar($aggregations, $query, $totalResults, $findUrl)
    {
        $this->findUrl = $findUrl;
        $this->totalResults = $totalResults;

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

        $title = __('Refine your search');
        $html = "<div class='facet-sections-title'>$title</div>";

        // Display all the facet entries.
        $html .= $this->emitHtmlForFacetSections();

        return $html;
    }

    protected function emitHtmlLinkForAddFilter($facetToAddGroup, $facetToAddName, $facetToAddRootPath, $isRoot)
    {
        // Add an argument to the query string for the facet now being added.
        $updatedQueryString = $this->editQueryStringToAddFacetArg($facetToAddGroup, $facetToAddRootPath, $isRoot);

        // Create the link that the user can click to apply this facet plus all the already applied facets.
        // In the link text, add a space after each comma for readability.
        $facetUrl = $this->findUrl . '?' . $updatedQueryString;

        $link = '<a href="' . $facetUrl . '" class="search-link">' . $facetToAddName . '</a>';
        return $link;
    }

    protected function emitHtmlLinkForRemoveFilter($facetToRemoveGroup, $facetToRemoveName, $facetToRemoveRootPath, $isRoot)
    {
        $updatedQueryString = $this->editQueryStringToRemoveFacetArg($this->queryStringWithApplieFacets, $facetToRemoveGroup, $facetToRemoveRootPath, $isRoot);

        // Create the link that the user can click to remove this facet, but leave all the other applied facets.
        // In the facet text, add a space after each comma for readability.
        $facetUrl = $this->findUrl . '?' . $updatedQueryString;
        $link = $facetToRemoveName . '<a href="' . $facetUrl . '" title="Remove filter" class="search-reset-link">' . '&#10006;' . '</a>';
        return $link;
    }

    protected function entryHasNoFilteringEffect($entry)
    {
        // See if this entry's count is less than the total number of search results.
        // if not, the entry will have no effect since it cannot be used to further narrow the results.
        //return false;
        return $entry['count'] >= $this->totalResults && $entry['action'] == 'add';
    }

    protected function extractAppliedFacetsFromSearchRequest($query)
    {
        $appliedFacets = array(FACET_KIND_ROOT => array(), FACET_KIND_LEAF => array());

        $queryStringRoots = isset($query[FACET_KIND_ROOT]) ? $query[FACET_KIND_ROOT] : array();
        $queryStringFacets = isset($query[FACET_KIND_LEAF]) ? $query[FACET_KIND_LEAF] : array();

        foreach ($queryStringRoots as $group => $facetValues)
        {
            if (!$this->isDefinedGroup($group))
                continue;

            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[FACET_KIND_ROOT][$group][] = $facetValue;
            }
        }

        foreach ($queryStringFacets as $group => $facetValues)
        {
            if (!$this->isDefinedGroup($group))
                continue;

            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[FACET_KIND_LEAF][$group][] = $facetValue;
            }
        }

        $this->appliedFacets = $appliedFacets;
    }

    protected function findAppliedFacetInFacetsTable($group, $facetName)
    {
        if (!isset($this->facetsTable[$group]))
        {
            // Normally this won't happen, but it could if the caller is attempting to look for a group that's not in
            // the facets table, e.g. a facet used only for shared results when the results are not shared.
            return -1;
        }

        $facetIndex = 0;
        foreach ($this->facetsTable[$group] as $index => $facetTableEntry)
        {
            if ($facetTableEntry['name'] == $facetName)
            {
                $facetIndex = $index;
                break;
            }
        }
        return $facetIndex;
    }

    public function getFacetDefinitions()
    {
        return $this->facetDefinitions;
    }

    public function getFacetFilters($roots, $leafs)
    {
        // Create the filter portion of the query to limit the results to specific facet values.
        // The results only contain results that satisfy the filters.

        $filters = array();

        foreach ($this->facetDefinitions as $group => $facetDefinition)
        {
            $filters = $this->createFacetFilter($filters, $roots, $leafs, $facetDefinition);
        }

        return $filters;
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
            else
            {
                if ($partsCount == 3)
                {
                    // Example: 'Image,Photograph,Print' => 'Image,Photograph,Print'
                    $leaf .= ",$parts[1],$parts[2]";
                }
                else
                {
                    if ($partsCount > 3)
                    {
                        // Example: 'Image,Photograph,Negative,Glass Plate' => 'Image,Photograph,Glass Plate'
                        $leaf .= ",$parts[1],$lastPart";
                    }
                }
            }
        }
        else
        {
            $leaf = $lastPart;
        }

        return array(FACET_KIND_ROOT => $root, FACET_KIND_LEAF => $leaf);
    }

    protected function getFacetValueForDate($text)
    {
        if ($text == BLANK_FIELD_TEXT)
            return $text;

        $year = '';
        if (preg_match("/^.*(\d{4}).*$/", $text, $matches))
        {
            $year = $matches[1];
        }

        if (empty($year))
        {
            // This date is malformed.
            $value = BLANK_FIELD_TEXT;
        }
        else
        {
            $decade = $year - ($year % 10);
            $value = $decade . "s";
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
                $showRoot = $this->facetDefinitions[$elasticsearchFieldName]['is_root_hierarchy'];
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
                else
                {
                    $values[] = $text;
                }
            }
        }

        return $values;
    }

    public function getFilterBarFacets($query)
    {
        // Create data for applied facets that the Search Results filter bar logic can use
        // to report to the user which facets are applied in the facets sidebar.

        $this->extractAppliedFacetsFromSearchRequest($query);
        $filterBarFacets = array();
        $appliedRootFacets = $this->appliedFacets['root'];
        $appliedLeafFacets = $this->appliedFacets['leaf'];

        $this->queryStringWithApplieFacets = $this->createQueryStringWithFacets($query);

        // Add all the leaf facets to the filter bar facets.
        foreach ($appliedLeafFacets as $leafFacetGroup => $leafFacetNames)
        {
            foreach ($leafFacetNames as $index => $leafFacetName)
            {
                // Check for the special case where the user applied a child facet such as 'Image,Photograph' and then
                // applied a grandchild facet like 'Glass Plate'. In this case, don't show the child, only the grandchild.
                if (count($leafFacetNames) == 2 && $index == 0)
                {
                    if (strpos($leafFacetNames[1], $leafFacetNames[0] . ',') === 0)
                    {
                        // The 0th value is the child and the 1st is the grandchild. Ignore the child.
                        continue;
                    }
                }

                $groupName = $this->facetDefinitions[$leafFacetGroup]['name'];
                $facetToRemoveRootPath = $leafFacetName;
                $parts = explode(',', $facetToRemoveRootPath);
                $facetToRemoveName = $parts[count($parts) - 1];
                $resetLink = $this->emitHtmlLinkForRemoveFilter($leafFacetGroup, $facetToRemoveName, $facetToRemoveRootPath, false);
                $filterBarFacets[$groupName]['reset'][] = $resetLink;
            }
        }

        // Add only those root facets that don't have one of their leafs applied.
        foreach ($appliedRootFacets as $rootFacetGroup => $rootFacetNames)
        {
            foreach ($rootFacetNames as $rootFacetName)
            {
                $groupName = $this->facetDefinitions[$rootFacetGroup]['name'];
                $skipThisRoot = false;

                // Check to see if this root is the root of a leaf that's applied
                if (isset($filterBarFacets[$groupName]['name']))
                {
                    foreach ($filterBarFacets[$groupName]['name'] as $facetName)
                    {
                        if (strpos($facetName, $rootFacetName . ',') === 0)
                        {
                            // One of this root's leafs is applied so ignore this root.
                            $skipThisRoot = true;
                            break;
                        }
                    }
                }

                if (!$skipThisRoot)
                {
                    // It's okay to add this root since none of its leaf facets are applied.
                    $resetLink = $this->emitHtmlLinkForRemoveFilter($rootFacetGroup, $rootFacetName, $rootFacetName, true);
                    $filterBarFacets[$groupName]['reset'][] = $resetLink;
                }
            }
        }

        ksort($filterBarFacets);

        if (count($filterBarFacets) >= 2)
        {
            $resetLink = $this->emitHtmlForResetLink($query);
            $filterBarFacets[__('Clear all')] = ['reset' => [$resetLink]];
        }

        return $filterBarFacets;
    }

    public function getRootAndFirstChildNameFromLeafName($leafName)
    {
        // Get the root and its first child from the leaf name.
        $rootName = $this->getRootNameFromLeafName($leafName);
        $firstChildName = '';
        $remainder = substr($leafName, strlen($rootName) + 1);
        if (strlen($remainder) > 0)
        {
            $firstChildName = ',' . $this->getRootNameFromLeafName($remainder);
        }

        return $rootName . $firstChildName;
    }

    protected function getRootNameFromLeafName($leafName)
    {
        $rootName = $leafName;
        $index = strpos($leafName, ',');
        if ($index !== false)
        {
            $rootName = substr($leafName, 0, $index);
        }
        return $rootName;
    }

    protected function isDefinedGroup($group)
    {
        // Check to see if the group is valid. It can be bad if the user mistyped or edited the query string,
        // for example by specifying 'root_tpe[]=image' instead of 'root_type[]=image'.
        return isset($this->facetDefinitions[$group]);
    }

    protected function setFacetsTableActions()
    {
        $appliedRootFacets = $this->appliedFacets['root'];
        $appliedLeafFacets = $this->appliedFacets['leaf'];

        // Examine every applied root facet name with the goal of marking each as removable.
        foreach ($appliedRootFacets as $rootFacetGroup => $rootFacetNames)
        {
            foreach ($rootFacetNames as $rootFacetName)
            {
                // Find this applied facet name in the facets table.
                $facetIndex = $this->findAppliedFacetInFacetsTable($rootFacetGroup, $rootFacetName);

                // Set this applied facet's action to remove
                $this->facetsTable[$rootFacetGroup][$facetIndex]['action'] = 'remove';

                if (isset($this->facetsTable[$rootFacetGroup][$facetIndex]['leafs']))
                {
                    // See the action for each of the root's leafs.
                    foreach ($this->facetsTable[$rootFacetGroup][$facetIndex]['leafs'] as $index => $leaf)
                    {
                        // Determine if this leaf is already applied. First check if the applied facets array
                        // contains any leafs for this leaf's groupt. For example, if this leaf is in the 'subject'
                        // group, make sure there is at least one applied 'subject' leaf.
                        $leafIsApplied = false;
                        if (isset($appliedLeafFacets[$leaf['group']]))
                        {
                            // Determine if this leaf matches any of the leafs in the group.
                            foreach ($appliedLeafFacets[$leaf['group']] as $facetRootPath)
                            {
                                if ($facetRootPath == $leaf['root_path'])
                                {
                                    $leafIsApplied = true;
                                    break;
                                }
                            }
                        }

                        if ($leafIsApplied)
                        {
                            // Since this leaf facet is applied, don't show the remove-X for the root facet. This is to
                            // avoid the confusion of allowing the user to remove the root facet while the search results
                            // are still limited by the leaf facet which would have no effect because the leaf facet is
                            // more restrictive than the root facet. By disabling the root's remove=X, the user must
                            // first 'undo' the application of the leaf facet which will then restore the remove-X for
                            // the root facet.
                            $actionKind = 'remove';
                            $this->facetsTable[$rootFacetGroup][$facetIndex]['action'] = 'none';
                        }
                        else
                        {
                            // Make the leaf visible unless it has the same name as the root.
                            if ($leaf['root'] == $leaf['name'])
                                $actionKind = 'hide';
                            else
                                $actionKind = 'add';
                        }
                        $this->facetsTable[$rootFacetGroup][$facetIndex]['leafs'][$index]['action'] = $actionKind;
                    }
                }
            }
        }

        foreach ($appliedLeafFacets as $leafFacetGroup => $leafFacetNames)
        {
            // Set facet table actions for leaf facets that are not part of a root hierarchy
            // (root hierarchy leafs were processed in the code above.
            if ($this->facetDefinitions[$leafFacetGroup]['is_root_hierarchy'])
            {
                // Ignore root hierarchy facet leafs.
                continue;
            }

            // Find this applied facet name in the facets table.
            foreach ($leafFacetNames as $leafFacetName)
            {
                // Find this applied facet name in the facets table.
                $facetIndex = $this->findAppliedFacetInFacetsTable($leafFacetGroup, $leafFacetName);

                if ($facetIndex == -1)
                {
                    // This facet is not in the table.
                    continue;
                }

                // Set this applied facet's action to remove
                $this->facetsTable[$leafFacetGroup][$facetIndex]['action'] = 'remove';
            }
        }
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