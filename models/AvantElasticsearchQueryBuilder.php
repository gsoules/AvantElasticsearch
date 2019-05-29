<?php
class AvantElasticsearchQueryBuilder extends AvantElasticsearch
{
    protected $avantElasticsearchFacets;
    protected $usingSharedIndex;

    public function __construct()
    {
        parent::__construct();

        $this->usingSharedIndex = AvantElasticsearch::useSharedIndexForQueries();
        $indexName = $this->usingSharedIndex ? self::getNameOfSharedIndex() : self::getNameOfLocalIndex();
        $this->setIndexName($indexName);

        $this->avantElasticsearchFacets = new AvantElasticsearchFacets();
    }

    public function constructSearchQueryParams($options, $commingled)
    {
        if (!isset($options['query']) || !is_array($options['query']))
        {
            throw new Exception("Query parameter is required to execute elasticsearch query.");
        }

        $offset = isset($options['offset']) ? $options['offset'] : 0;
        $limit = isset($options['limit']) ? $options['limit'] : 20;
        $terms = isset($options['query']['query']) ? $options['query']['query'] : '';
        $roots = isset($options['query'][FACET_KIND_ROOT]) ? $options['query'][FACET_KIND_ROOT] : [];
        $leafs = isset($options['query'][FACET_KIND_LEAF]) ? $options['query'][FACET_KIND_LEAF] : [];
        $sort = isset($options['sort']) ? $options['sort'] : null;

        // Fields that the query will return.
        $source = [
            'element.*',
            'item.*',
            'tags',
            'html-fields',
            'pdf.file-name',
            'pdf.file-url',
            'url.*'
        ];

        // Highlighting the query will return.
        $highlight =
            ['fields' =>
                [
                    'element.description' =>
                    (object)[
                        'number_of_fragments' => 0,
                        'pre_tags' => ['<span class="hit-highlight">'],
                        'post_tags' => ['</span>']
                    ],
                    'pdf.text-*' =>
                    (object)[
                        'number_of_fragments' => 3,
                        'fragment_size' => 150,
                        'pre_tags' => ['<span class="hit-highlight">'],
                        'post_tags' => ['</span>']
                    ]
                ]
        ];

        $mustQuery = [
            "multi_match" => [
                'query' => $terms,
                'type' => "cross_fields",
                'analyzer' => "english",
                'operator' => "and",
                'fields' => [
                    "item.title^15",
                    "element.title^10",
                    "element.identifier^2",
                    "element.*",
                    "tags",
                    "pdf.text-*"
                ]
            ]
        ];

        $shouldQuery[] = [
            "match" => [
                "element.type" => [
                    "query" => "reference",
                    "boost" => 2
                ]
            ]
        ];

        $body['_source'] = $source;
        $body['highlight'] = $highlight;
        $body['query']['bool']['must'] = $mustQuery;
        $body['query']['bool']['should'] = $shouldQuery;

        // Create the aggregations portion of the query to indicate which facet values to return.
        // All requested facet values are returned for the entire set of results.
        $aggregations = $this->avantElasticsearchFacets->createAggregationsForElasticsearchQuery($commingled);
        $body['aggregations'] = $aggregations;

        // Create the filter portion of the query to limit the results to specific facet values.
        // The results only contain results that satisfy the filters.
        $filters = $this->avantElasticsearchFacets->getFacetFiltersForElasticsearchQuery($roots, $leafs);

        if (!$commingled)
        {
            // Until support is in place for searching the local index, filter the results to only show those
            // contributed by this installation.
            $contributorId = ElasticsearchConfig::getOptionValueForContributorId();
            $filters[] = array('term' => ['item.contributor-id' => $contributorId]);

            if ($options['public'])
            {
                // Filter results to only contain public items.
                $filters[] = array('term' => ['item.public' => true]);
            }
        }

        if ($options['files'])
        {
            // Filter results to only contain items that have a file attached and thus have an image.
            $filters[] = array('exists' => ['field' => "url.image"]);
        }

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
            'index' => $this->getNameOfActiveIndex(),
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
            'index' => $this->getNameOfActiveIndex(),
            'body' => [
                '_source' => [
                    'suggestions', 'item.title'
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

    public function constructTermAggregationsQueryParams($fieldName)
    {
        $params = [
            'index' => $this->getNameOfActiveIndex(),
            'body' => [
                'size' => 0,
                'aggregations' => [
                    'contributors' => [
                        'terms' => [
                            'field' => $fieldName
                        ],
                        'aggregations' => [
                            'files' => [
                                'sum' => [
                                    'field' => 'item.file-count'
                                ]
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

    public function isUsingSharedIndex()
    {
        return $this->usingSharedIndex;
    }
}