<?php
class AvantElasticsearchFacets extends AvantElasticsearch
{
    protected $facetNames = array();

    public function __construct()
    {
        parent::__construct();

        // The order here determines the filter order on the search results page.
        $this->facetNames = array(
            'type' => 'Item Types',
            'subject' => 'Subjects',
            'place' => 'Places',
            'date' => 'Dates',
            'tag' => 'Tags'
        );
    }

    public function createAddFacetLink($queryString, $facetToAdd, $facetValue)
    {
        if ($facetToAdd == 'subject' || $facetToAdd == 'tag')
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
            if (!is_array($values))
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

        foreach ($beforeArgs as $rawArg)
        {
            // Decode any %## encoding in the arg and change '+' to a space character.
            $arg = urldecode($rawArg);
            $facetArg = "facet_$facetToRemove";

            $argContainsFacet = strpos($arg, $facetArg) !== false;
            $argContainsFacetValue = strpos($arg, $facetValue) !== false;

            if (!($argContainsFacet && $argContainsFacetValue))
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
        return $this->facetNames;
    }

    public function getFacetValuesForElement($elementName, $elasticsearchFieldName, $fieldTexts)
    {
        $values = array();

        if (array_key_exists($elasticsearchFieldName, $this->facetNames))
        {
            foreach ($fieldTexts as $fieldText)
            {
                $text = $fieldText['text'];

                if ($elementName == 'Place' || $elementName == 'Type' || $elementName == 'Subject')
                {
                    $value = $this->getFacetValueForHierarchy($elementName, $text);
                }
                else if ($elementName == 'Date')
                {
                    $value = $this->getFacetValueForDate($text);
                }

                if (!empty($value))
                {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    protected function getFacetValueForDate($text)
    {
        $value = array();

        if ($text == '')
        {
            $value[] = __('Unknown');
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
                $value[] = $decade . "'s";
            }
        }

        return $value;
    }

    protected function getFacetValueForHierarchy($elementName, $text)
    {
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
        $value = $root . $separator . $leaf;

//        if ($elementName == 'Type' || $elementName == 'Subject')
//        {
//            if (!empty($root) && !empty($leaf)) {
//                // Emit the root as the top of the hierarchy.
//                $facetValues[] = $root;
//            }
//        }

        return $value;
    }
}