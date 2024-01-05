<?php

class AvantElasticsearchFacets extends AvantElasticsearch
{
    // Root refers to the top value in a hierarchy facet.
    // Leaf refers to either the elided leaf value in a hierarchy facet or simply the value in a non-hierarchy facet.
    const FACET_KIND_ROOT = 'root';
    const FACET_KIND_LEAF = 'leaf';

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
            $count = number_format($facetTableEntry['count']);
            $html .= " ($count)";
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
        // sub-aggregate contains its own array of buckets with each bucket containing a leaf value and a count to
        // indicate how many of the search results contain the leaf value for that root. For non-hierarchy facets
        // like Date, there are no sub-aggregates. In this code, both the leaf values for hierarchy facets and the
        // top level values for non-hierarchy facets are referred to as leafs. Only the root values of hierarchy
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
                $kind = $isRoot ? self::FACET_KIND_ROOT : self::FACET_KIND_LEAF;
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

        if (!empty($query))
        {
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

            if (is_array($value))
            {
                if ($arg != 'advanced')
                {
                    // Ignore any unexpected array values. This could happen if the user mistyped or edited the query string,
                    // for example by specifying 'rot_type[]=Image' instead of 'root_type[]=Image'.
                    continue;
                }

                foreach ($value as $index => $advancedArg)
                {
                    if (!is_array($advancedArg))
                    {
                        // Again ignore any unexpected query string syntax.
                        continue;
                    }

                    if (!array_key_exists('type', $advancedArg))
                        continue;
                    if (!array_key_exists('element_id', $advancedArg))
                        continue;
                    $updatedQueryString .= "&advanced[$index][element_id]=" . urlencode($advancedArg['element_id']);
                    $updatedQueryString .= "&advanced[$index][type]=" . urlencode($advancedArg['type']);

                    // Test if the terms arg is present. It won't be for conditions Empty and Not Empty.
                    if (isset($advancedArg['terms']))
                        $updatedQueryString .= "&advanced[$index][terms]=" . urlencode($advancedArg['terms']);
                }
            }
            else
            {
                $updatedQueryString .= '&' . urlencode("$arg") . '=' . urlencode($value);
            }
        }

        $this->queryStringWithApplieFacets = $updatedQueryString;
        return $updatedQueryString;
    }

    protected function defineFacets()
    {
        // The order below is the order in which facet names appear in the facets section on the search results page.

        $this->createFacet('subject', 'Subject', true, true);
        $this->createFacet('type', 'Type', true, true);
        $this->createFacet('place', 'Place', true, false);

        $this->createFacet('date', 'Date');
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
        $kind = $isRoot ? self::FACET_KIND_ROOT : self::FACET_KIND_LEAF;
        $facetArg = "{$kind}_{$facetToAddGroup}[]";
        $argToAdd = "$facetArg=$facetToAddValue";

        // Add the arg to be added to the new query string.
        $args[] = $argToAdd;

        $updatedQueryString = implode('&', $args);
        return $updatedQueryString;
    }

    public function editQueryStringToRemoveFacetArg($queryString, $facetToRemoveGroup, $facetToRemoveValue, $isRoot)
    {
        // Construct the name/value of the arg to be remove.
        $argToRemove = "{$facetToRemoveGroup}[]={$facetToRemoveValue}";

        // Looo over each arg in the query string.
        $args = explode('&', $queryString);
        foreach ($args as $index => $rawArg)
        {
            // Remove any %# encoding from the arg so it can be compared to the arg to be removed.
            $arg = urldecode($rawArg);

            // Remove the 'root_' or 'leaf_' prefix from the arg.
            $arg = substr($arg, 5);

            // Remove the arg to be remove and all of its child args. The child args have $argToRemove as a prefix.
            // This lets you drill down into a hierarchy, but then come back up anywhere in the middle without
            // having to go up one level at a time.
            $prefixLength = strlen($argToRemove);
            $argIsChildOfArgToRemove = substr($arg, 0, $prefixLength) == $argToRemove;
            if ($argIsChildOfArgToRemove)
            {
                unset($args[$index]);
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
        $html .= $this->emitHtmlForFacetEntryListItem($facetEntryHtml, $entry['action'], 1, $isRoot);

        // Emit hierarchy leaf entries for this facet entry.
        if (isset($entry['leafs']))
        {
            $leafEntries = $entry['leafs'];
            $leafEntryListItems = array();

            foreach ($leafEntries as $index => $leafEntry)
            {
                if ($leafEntry['action'] == 'hide')
                {
                    continue;
                }

                $facetApplied = $leafEntry['action'] == 'remove' ? true : $facetApplied;
                if ($this->entryHasNoFilteringEffect($leafEntry))
                {
                    $leafEntry['action'] = 'dead';
                }

                $parts = array_map('trim', explode(',', $leafEntry['name']));

                // Create the structure of the hierarchy of terms for this entry starting at the second level
                // e.g. starting at Object,Clothing where Object is at level 1 and Clothing is at level 2.
                $level = 1;
                foreach ($parts as $part)
                {
                    $level += 1;
                    if ($level > 6)
                    {
                        // This is an arbitrary, but practical limit. If you make it higher, add additional
                        // CSS for the facet-entry- class to style levels above .facet-entry-6.
                        break;
                    }
                    $leafEntryListItems[$index]['entry'] = $leafEntry;
                    $leafEntryListItems[$index]['entry']['name'] = $part;
                    $leafEntryListItems[$index]['level'] = $level;
                    $leafEntryListItems[$index]['action'] = $leafEntry['action'];
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

        $title = __('Refine Your Search');
        $html = "<div id='search-facet-title'>$title</div>";
        $html .= "<div id='search-facet-button' class='search-facet-closed'>$title</div>";

        // Display all the facet entries.
        $html .= "<div id='search-facet-sections'>";
        $html .= $this->emitHtmlForFacetSections();
        $html .= "</div>";

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
        // Create the link that the user can click to remove this facet, but leave all the other applied facets.
        $facetUrl = $this->getUrlForRemoveFilter($facetToRemoveGroup, $facetToRemoveRootPath, $isRoot);
        $link = $facetToRemoveName . AvantSearch::getSearchFilterResetLink($facetUrl);
        return $link;
    }

    protected function entryHasNoFilteringEffect($entry)
    {
        // See if this entry's count, and the count of each of its leafs, is greater than or equal to the total number
        // of search results. If true, the entry will have no effect since it cannot be used to further narrow the
        // results. We have to check the leaf counts because a root count could be the same as the total number of
        // results, but the leafs could provide further filtering.
        if ($entry['count'] >= $this->totalResults && $entry['action'] == 'add')
        {
            $leafs = isset($entry['leafs']) ? $entry['leafs'] : array();
            foreach ($leafs as $leaf)
            {
                if ($leaf['count'] < $this->totalResults)
                    return false;
            }
            return true;
        }

        return false;
    }

    protected function extractAppliedFacetsFromSearchRequest($query)
    {
        $appliedFacets = array(self::FACET_KIND_ROOT => array(), self::FACET_KIND_LEAF => array());

        $queryStringRoots = isset($query[self::FACET_KIND_ROOT]) ? $query[self::FACET_KIND_ROOT] : array();
        $queryStringFacets = isset($query[self::FACET_KIND_LEAF]) ? $query[self::FACET_KIND_LEAF] : array();

        foreach ($queryStringRoots as $group => $facetValues)
        {
            if (!$this->isDefinedGroup($group))
                continue;

            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[self::FACET_KIND_ROOT][$group][] = $facetValue;
            }
        }

        foreach ($queryStringFacets as $group => $facetValues)
        {
            if (!$this->isDefinedGroup($group))
                continue;

            foreach ($facetValues as $facetValue)
            {
                $appliedFacets[self::FACET_KIND_LEAF][$group][] = $facetValue;
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

            if ($partsCount >= 2)
            {
                foreach ($parts as $index => $part)
                {
                    if ($index == 0)
                        continue;

                    $leaf .= ",$part";
                }
            }
        }
        else
        {
            $leaf = $lastPart;
        }

        return array(self::FACET_KIND_ROOT => $root, self::FACET_KIND_LEAF => $leaf);
    }

    protected function getFacetValueForDate($text)
    {
        if ($text == BLANK_FIELD_TEXT)
            return $text;

        $year = $this->getYearFromDate($text);

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

    public function getFacetValuesForElement($elasticsearchFieldName, $fieldTexts, $forSharedIndex)
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
            if ($forSharedIndex && isset($fieldText['mapping']) && $fieldText['mapping'] == 'unmapped')
            {
                // Exclude unmapped local values from the facets in a shared index. This filtering is what prevents
                // a site's local vocabulary terms from appearing in the facets for shared search results.
                continue;
            }
            // Determine if these field texts have separate shared index values. If not, use the one value
            // that's there for both shared and local.
            $key = $forSharedIndex && isset($fieldText['text-shared-index']) ? 'text-shared-index' : 'text';

            $text = $fieldText[$key];

            $facetDefinition = $this->facetDefinitions[$elasticsearchFieldName];

            if ($facetDefinition['is_hierarchy'])
            {
                // Get the root and leaf for hierarchy values.
                $showRoot = $this->facetDefinitions[$elasticsearchFieldName]['is_root_hierarchy'];
                $hierarchy = $this->getFacetHierarchyParts($text, $showRoot);

                if ($showRoot)
                {
                    $values[] = array(self::FACET_KIND_ROOT => $hierarchy[self::FACET_KIND_ROOT], self::FACET_KIND_LEAF => $hierarchy[self::FACET_KIND_LEAF]);
                }
                else
                {
                    $values[] = $hierarchy[self::FACET_KIND_LEAF];
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

        // Add root facets to the filter bar.
        foreach ($appliedRootFacets as $rootFacetGroup => $rootFacetNames)
        {
            foreach ($rootFacetNames as $rootFacetName)
            {
                $groupName = $this->facetDefinitions[$rootFacetGroup]['name'];
                $hideRootFacet = false;
                if ($hideRootFacet && isset($appliedLeafFacets[$rootFacetGroup]))
                {
                    // Don't show a root facet if one of its leafs is applied. This is one of those things that doesn't
                    // really work well whether you show it or not. If you show the root and the user removes it, all
                    // the leaves get removed too. If you don't show the root and the user removes the leaf, the root
                    // facet returns which is unexpected.
                    continue;
                }
                $resetUrl = $this->getUrlForRemoveFilter($rootFacetGroup, $rootFacetName, true);
                $filterBarFacets[$groupName]['reset-url'][] = $resetUrl;
                $filterBarFacets[$groupName]['reset-text'][] = "$groupName: $rootFacetName";
            }
        }

        // Add leaf facets to the filter bar.
        foreach ($appliedLeafFacets as $leafFacetGroup => $leafFacetNames)
        {
            foreach ($leafFacetNames as $index => $leafFacetName)
            {
                $groupName = $this->facetDefinitions[$leafFacetGroup]['name'];
                $facetToRemoveRootPath = $leafFacetName;
                $parts = explode(',', $facetToRemoveRootPath);
                $facetToRemoveName = $parts[count($parts) - 1];
                $resetUrl = $this->getUrlForRemoveFilter($leafFacetGroup, $facetToRemoveRootPath, false);
                $filterBarFacets[$groupName]['reset-url'][] = $resetUrl;
                $filterBarFacets[$groupName]['reset-text'][] = "$groupName: $facetToRemoveName";
                $filterBarFacets[$groupName]['root-name'][] = $parts[0];
            }
        }

        ksort($filterBarFacets);

        $showClearAllButton = false;
        if ($showClearAllButton && count($filterBarFacets) >= 2)
        {
            $resetLink = $this->getUrlForResetLink($query);
            $filterBarFacets[__('Clear all')] = ['reset-url' => [$resetLink], 'reset-text' => [__('Clear all')]];
        }

        return $filterBarFacets;
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

    protected function getUrlForRemoveFilter($facetToRemoveGroup, $facetToRemoveRootPath, $isRoot)
    {
        $updatedQueryString = $this->editQueryStringToRemoveFacetArg($this->queryStringWithApplieFacets, $facetToRemoveGroup, $facetToRemoveRootPath, $isRoot);
        $url = $this->findUrl . '?' . $updatedQueryString;
        return $url;
    }

    protected function getUrlForResetLink($query)
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
            if ($argName == 'query' || $argName == 'root' || $argName == 'leaf' || $argName == 'page' || $argName == 'advanced')
                continue;

            $otherArgs .= "&$argName=$argValue";
        }

        $terms = isset($query['query']) ? $query['query'] : '';
        $queryString = "query=" . urlencode($terms);
        $resetUrl = $this->findUrl . '?' . $queryString . $otherArgs;
        return $resetUrl;
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
                            $actionKind = 'remove';
                        }
                        else
                        {
                            if ($leaf['root'] == $leaf['name'])
                            {
                                // Don't expand this facet because the leaf is also the root. An example is the
                                // top-level Subject facet `People` which has no child facets. To expand it would show
                                // a leaf 'People' beneath the root 'People',
                                $actionKind = 'hide';
                            }
                            else
                            {
                                // This logic implements the behavior whereby the first time you click on any root facet,
                                // e.g. Subject `Structures`, that facet expands, but only shows its immediate children
                                // (else part below). If you click on one of those children (apply that leaf facet),
                                // all facets for all roots show (if case below). But if instead you click on another
                                // root facet (such that no leaf facets are applied), only the immediate children of that
                                // root show (else case below). This approach limits facet expansion until a leaf facet has
                                // been applied so that a user does not see too many facets too soon. Once a leaf has been
                                // applied, the number of matching items, and thus the number of facets, becomes greatly
                                // reduced and so the showing of of all facets is not overwhelming.
                                if ($appliedLeafFacets)
                                {
                                    $actionKind = 'add';
                                }
                                else
                                {
                                    // Expand this facet only if it is an immediate child of an applied root facet.
                                    $parts = explode(',', $leaf["root_path"]);
                                    $depth = count($parts);
                                    $actionKind = $depth == 2 ? 'add' : 'hide';
                                }
                            }
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