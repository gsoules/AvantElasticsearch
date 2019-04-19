<?php
class AvantElasticsearchQueryBuilder extends AvantElasticsearch
{
    protected $avantElasticsearchFacets;

    public function __construct()
    {
        parent::__construct();

        $this->avantElasticsearchFacets = new AvantElasticsearchFacets();
    }

    public function constructSearchQueryParams($options)
    {
        if (!isset($options['query']) || !is_array($options['query']))
        {
            throw new Exception("Query parameter is required to execute elasticsearch query.");
        }

        $offset = isset($options['offset']) ? $options['offset'] : 0;
        $limit = isset($options['limit']) ? $options['limit'] : 20;
        $terms = isset($options['query']['query']) ? $options['query']['query'] : '';
        $facets = isset($options['query']['facet']) ? $options['query']['facet'] : [];
        $roots = isset($options['query']['root']) ? $options['query']['root'] : [];
        $sort = isset($options['sort']) ? $options['sort'] : null;

        // Fields that the query will return.
        $source = [
            'itemid',
            'ownerid',
            'owner',
            'public',
            'url',
            'thumb',
            'image',
            'files',
            'element.*',
            'html',
            'tags'
        ];

        // Highlighting the query will return.        $highlight = ['fields' =>
        $highlight =
            ['fields' =>
                ['element.description' =>
                    (object)[
                        'number_of_fragments' => 0,
                        'pre_tags' => ['<span class="elasticsearch-highlight">'],
                        'post_tags' => ['</span>']
                    ]
                ]
        ];

        $mustQuery = [
            "multi_match" => [
                'query' => $terms,
                'type' => "cross_fields",
                'operator' => "and",
                'fields' => [
                    "title^5",
                    "element.title^15",
                    "element.identifier^2",
                    "element.*"
                ]
            ]
        ];

        $shouldQuery[] = [
            "match" => [
                "element.type" => [
                    "query" => "reference",
                    "boost" => 10
                ]
            ]
        ];

        $body['_source'] = $source;
        $body['highlight'] = $highlight;
        $body['query']['bool']['must'] = $mustQuery;
        $body['query']['bool']['should'] = $shouldQuery;

        // Create the aggregations portion of the query to indicate which facet values to return.
        // All requested facet values are returned for the entire set of results.
        $aggregations = $this->avantElasticsearchFacets->createAggregationsForElasticsearchQuery();
        $body['aggregations'] = $aggregations;

        // Create the filter portion of the query to limit the reults to specific facet values.
        // The results only contain results that satisfy the filters.
        $filters = $this->avantElasticsearchFacets->getFacetFiltersForElasticsearchQuery($roots, $facets);
        if (count($filters) > 0)
        {
            $body['query']['bool']['filter'] = $filters;
        }

        if (isset($sort))
        {
            // Specify sort criteria and also compute scores to be used as the final sort criteria.
            $body['sort'] = $sort;
            $body['track_scores'] = true;
        }

        $params = [
            'index' => $this->getElasticsearchIndexName(),
            'from' => $offset,
            'size' => $limit,
            'body' => $body
        ];

        return $params;
    }

    public function constructSuggestQueryParams($prefix, $fuzziness, $size)
    {
        // Note that skip_duplicates is false to ensure that all the right values are returned.
        // The Elasticsearch documentation also says that performance is better when false.

        $params = [
            'index' => 'omeka',
            'body' => [
                '_source' => [
                    'suggestions', 'title'
                ],
                'suggest' => [
                    'keywords-suggest' => [
                        'prefix' => $prefix,
                        'completion' => [
                            'field' => 'suggestions',
                            'skip_duplicates' => false,
                            'size' => $size,
                            'fuzzy' =>
                                [
                                    'fuzziness' => $fuzziness ? 1 : 0
                                ]
                        ]
                    ]
                ]
            ]
        ];

        return $params;
    }

    public function getFacetDefinitions()
    {
        return $this->avantElasticsearchFacets->getFacetDefinitions();
    }
}