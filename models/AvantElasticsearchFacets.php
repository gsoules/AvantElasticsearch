<?php
class AvantElasticsearchFacets extends AvantElasticsearch
{
    public function __construct()
    {
        parent::__construct();
    }

    public function createAddFacetLink($queryString, $facetToAdd, $facetValue)
    {
        if ($facetToAdd == 'subject')
        {
            $arg = urlencode("facet_{$facetToAdd}[]") . "=" . urlencode($facetValue);
        }
        else
        {
            $arg = "facet_{$facetToAdd}=" . urlencode($facetValue);
        }

        if (strpos($queryString, $arg) === FALSE)
        {
            return "$queryString&$arg";
        }

        return $queryString;
    }

    public function createAggregationsForElasticsearchQuery()
    {
        $facetNames = $this->getFacetNames();

        // Create an array of aggregation terms.
        foreach ($facetNames as $aggregationName => $facetName)
        {
            $terms[$aggregationName] = [
                'terms' => [
                    'field' => "facet.$aggregationName.keyword",
                    'size' => 50,
                    'order' => ['_key' => 'asc']
                ]
            ];
        }

        // Convert the array into a nested object for the aggregation as required by Elasticsearch.
        $aggregations = (object)json_decode(json_encode($terms));

        return $aggregations;
    }

    protected function createFacetFilter($filters, $facets, $facetName, $aggregationName)
    {
        if (isset($facets[$aggregationName]))
        {
            $values = $facets[$aggregationName];

            if ($facetName != 'facet.subject.keyword')
            {
                $values = array($values);
            }

            // Create a separate term filter for each value so that the filters are ANDed
            // as opposed to using a single terms filter with multiple values that are ORed.
            foreach ($values as $value)
            {
                $filters[] = ['term' => [$facetName => $value]];
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
            if (is_array($facetValues))
            {
                foreach($facetValues as $k => $v)
                {
                    $queryString .= '&'.urlencode("facet_{$facetName}[]") . '=' . urlencode($v);
                }
            }
            else
            {
                $queryString .= '&'.urlencode("facet_{$facetName}") . '=' . urlencode($facetValues);
            }
        }

        return $queryString;
    }

    public function createRemoveFacetLink($queryString, $facetToRemove, $facetValue)
    {
        $beforeArgs = explode('&', $queryString);
        $afterArgs = array();

        foreach ($beforeArgs as $arg)
        {
            $argContainsFacet = strpos($arg, $facetToRemove) !== false;
            $argContainsValue = strpos($arg, $facetValue) !== false;
            if (!($argContainsFacet && $argContainsValue))
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
        $facetNames = $this->getFacetNames();

        foreach ($facetNames as $aggregationName => $facetName)
        {
            $filters = $this->createFacetFilter($filters, $facets, "facet.$aggregationName.keyword", $aggregationName);
        }

        return $filters;
    }

    public function getFacetNames()
    {
        $facetNames = array(
            'type' => 'Item Types',
            'subject' => 'Subjects',
            'place' => 'Places',
            'date' => 'Dates'
        );
        return $facetNames;
    }

    public function getFacetValue($elementName, $elasticsearchFieldName, $texts, &$facets)
    {
        $facetValues = array();

        foreach ($texts as $text)
        {
            if ($elementName == 'Place' || $elementName == 'Type' || $elementName == 'Subject')
            {
                $facetValues = $this->getFacetValueForHierarchy($elementName, $text, $facetValues);
            }
            else if ($elementName == 'Date')
            {
                $facetValues = $this->getFacetValueForDate($text, $facetValues);
            }

            $facetValuesCount = count($facetValues);
            if ($facetValuesCount >= 1)
            {
                if ($facetValuesCount > 1)
                {
                    $values = $facetValues;
                }
                else
                {
                    $values = $facetValues[0];
                }
                $facets[$elasticsearchFieldName] = $values;
            }
        }
    }

    protected function getFacetValueForDate($text)
    {
        $facetValues = array();

        if ($text == '')
        {
            $facetValues[] = __('Unknown');
        }
        else
        {
            $year = '';
            if (preg_match("/^.*(\d{4}).*$/", $text, $matches))
            {
                $year = $matches[1];
            }

            if (!empty($year)) {
                $decade = $year - ($year % 10);
                $facetValues[] = $decade . "'s";
            }
        }

        return $facetValues;
    }

    protected function getFacetValueForHierarchy($elementName, $text)
    {
        $facetValues = array();
        $root = '';

        if ($elementName == 'Type' || $elementName == 'Subject')
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
            // Filter out the ancestry to leave just the leaf text.
            $leaf = trim(substr($text, $index + 1));
        }

        if ($root == $leaf)
        {
            // The leaf and root are the same so get rid of the leaf value.
            $leaf = '';
        }

        // Form the facet value using the root and the leave (ignoring anything in the middle).
        $separator = empty($root) || empty($leaf) ? '' : ', ';
        $facetValues[] = $root . $separator . $leaf;

        return $facetValues;
    }
}