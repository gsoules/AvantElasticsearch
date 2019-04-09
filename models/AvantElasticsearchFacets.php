<?php
class AvantElasticsearchFacets extends AvantElasticsearch
{
    public function __construct()
    {
        parent::__construct();
    }

    public function constructFacets($elementName, $elasticsearchFieldName, $texts, &$facets)
    {
        $facetValues = array();
        foreach ($texts as $text)
        {
//                    if ($elementName == 'Type' || $elementName == 'Subject')
//                    {
//                        // Find the first comma.
//                        $needle = ', ';
//                        $pos1 = strpos($text, $needle);
//                        if ($pos1 !== false)
//                        {
//                            $pos2 = strpos($text, $needle, $pos1 + strlen($needle));
//                            if ($pos2 !== false) {
//                                // Filter out the ancestry to leave just the root text.
//                                $text = trim(substr($text, 0, $pos2));
//                            }
//                        }
//                        $facetValues[] = $text;
//                    }
            if ($elementName == 'Place' || $elementName == 'Type' || $elementName == 'Subject')
            {
                // Find the last comma.
                $index = strrpos($text, ',', -1);
                if ($index !== false)
                {
                    // Filter out the ancestry to leave just the leaf text.
                    $text = trim(substr($text, $index + 1));
                }
                $facetValues[] = $text;
            }
            else if ($elementName == 'Date')
            {
                // This code is only called if Date element is not empty.
                // As such, we can't use it to create an "Unknown date" facet value.
                $year = '';
                if (preg_match("/^.*(\d{4}).*$/", $text, $matches))
                {
                    $year = $matches[1];
                }

                if (!empty($year))
                {
                    $decade = $year - ($year % 10);
                    $facetValues[] = $decade . "'s";
                }
            }

            $facetValuesCount = count($facetValues);
            if ($facetValuesCount >= 1)
            {
                $facets[$elasticsearchFieldName] = $facetValuesCount > 1 ? $facetValues : $facetValues[0];
            }
        }
    }

    public function convertFacetValuesToString($value)
    {
        return is_array($value) ? implode(', ', $value) : $value;
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
            $value = $facets[$aggregationName];
            if ($facetName != 'facet.subject.keyword')
            {
                $value = array($value);
            }
            $filters[] = ['terms' => [$facetName => $value]];
        }
        return $filters;
    }

    public function createFacetLink($queryString, $facetToAdd, $value)
    {
        if ($facetToAdd == 'subject')
        {
            $arg = urlencode("facet_{$facetToAdd}[]") . "=" . urlencode($value);
        }
        else
        {
            $arg = "facet_{$facetToAdd}=" . urlencode($value);
        }

        if (strpos($queryString, $arg) === FALSE)
        {
            return "$queryString&$arg";
        }

        return $queryString;
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

    public function removeFacetFromQuery($queryString, $facetToRemove)
    {
        $beforeArgs = explode('&', $queryString);
        $afterArgs = array();

        foreach ($beforeArgs as $arg)
        {
            if (strpos($arg, $facetToRemove) === FALSE)
            {
                $afterArgs[] = $arg;
            }
        }
        return implode('&', $afterArgs);
    }
}