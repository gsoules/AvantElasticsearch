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

    public function constructSearchQueryParams($query, $limit, $sort, $public, $fileFilter, $commingled)
    {
        // Get parameter values or defaults.
        $indexId = isset($query['index']) ? $query['index'] : 0;
        $leafs = isset($query[FACET_KIND_LEAF]) ? $query[FACET_KIND_LEAF] : [];
        $page = isset($query['page']) ? $query['page'] : 1;
        $offset = ($page - 1) * $limit;
        $roots = isset($query[FACET_KIND_ROOT]) ? $query[FACET_KIND_ROOT] : [];
        $terms = isset($query['query']) ? $query['query'] : '';
        $viewId = isset($query['view']) ? $query['view'] : 1;

        // Initialize the query body.
        $body['_source'] = $this->constructSourceFields($viewId, $indexId);
        $body['query']['bool']['must'] = $this->constructMustQueryParams($terms);
        $body['query']['bool']['should'] = $this->constructShouldQueryParams();
        $body['aggregations'] = $this->constructAggregationsParams($commingled);;

        $highlightParams = $this->constructHighlightParams($viewId);
        if (!empty($highlightParams))
            $body['highlight'] = $highlightParams;

        // Create filters that will limit the query results.
        $queryFilters = $this->constructQueryFilters($public, $fileFilter, $roots, $leafs);

        // Create filters to limit results to specific contributors.
        $contributorFilters = $this->constructContributorFilters($commingled);
        if (!empty($contributorFilters))
            $queryFilters[] = $contributorFilters;

        // Add filters to the query body.
        if (count($queryFilters) > 0)
        {
            $body['query']['bool']['filter'] = $queryFilters;
        }

        // Specify if sorting by column. If not, sort is by relevance based on score.
        if (!empty($sort))
        {
            $body['sort'] = $sort;
        }

        // Compute scores even when not sorting by relevance.
        if ($viewId == SearchResultsViewFactory::TABLE_VIEW_ID)
            $body['track_scores'] = true;

        $params = [
            'index' => $this->getNameOfActiveIndex(),
            'from' => $offset,
            'size' => $limit,
            'body' => $body
        ];

        return $params;
    }

    protected function constructAggregationsParams($commingled)
    {
        // Create the aggregations portion of the query to indicate which facet values to return.
        // All requested facet values are returned for the entire set of results.

        $facetDefinitions = $this->avantElasticsearchFacets->getFacetDefinitions();
        foreach ($facetDefinitions as $group => $definition)
        {
            if ($definition['not_used'])
            {
                continue;
            }
            else if ($definition['commingled'] && !$commingled)
            {
                // This facet is only used when show commingled results.
                continue;
            }
            else if ($definition['is_root_hierarchy'])
            {
                // Build a sub-aggregation to get buckets of root values, each containing buckets of leaf values.
                $terms[$group] = [
                    'terms' => [
                        'field' => "facet.$group.root",
                        'size' => 128
                    ],
                    'aggregations' => [
                        'leafs' => [
                            'terms' => [
                                'field' => "facet.$group.leaf",
                                'size' => 128
                            ]
                        ]
                    ]
                ];

                // Sorting is currently required for hierarchical data because the logic for presenting hierarchical
                // facets indented as root > first-child > leaf is dependent on the values being sorted.
                $terms[$group]['terms']['order'] = array('_key' => 'asc');
                $terms[$group]['aggregations']['leafs']['terms']['order'] = array('_key' => 'asc');
            }
            else
            {
                // Build a simple aggregation to return buckets of values.
                $terms[$group] = [
                    'terms' => [
                        'field' => "facet.$group",
                        'size' => 128
                    ]
                ];

                if ($definition['sort'])
                {
                    // Sort the buckets by their values in ascending order. When not sorted, Elasticsearch
                    // returns them in reverse count order (buckets with the most values are at the top).
                    $terms[$group]['terms']['order'] = array('_key' => 'asc');
                }
            }
        }

        // Convert the array into a nested object for the aggregation as required by Elasticsearch.
        $aggregations = (object)json_decode(json_encode($terms));

        return $aggregations;
    }

    public function constructFileStatisticsAggregationParams()
    {
        $params = [
            'index' => $this->getNameOfLocalIndex(),
            'body' => [
                'size' => 0,
                'aggregations' => [
                    'contributors' => [
                        'terms' => [
                            'field' => 'item.contributor'
                        ],
                        'aggregations' => [
                            'audio' => [
                                'sum' => [
                                    'field' => 'file.audio'
                                ]
                            ],
                            'document' => [
                                'sum' => [
                                    'field' => 'file.document'
                                ]
                            ],
                            'image' => [
                                'sum' => [
                                    'field' => 'file.image'
                                ]
                            ],
                            'video' => [
                                'sum' => [
                                    'field' => 'file.video'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        return $params;
    }

    protected function constructContributorFilters($commingled)
    {
        $contributorFilters = array();

        if ($commingled)
        {
            // This is where we can add support to only display results from specific contributors. Somehow the
            // $contributorIds array needs to get populated with selections the user has made to indicate which contributors
            // they want to see results from. To show results from all contributors, return an empty array;

            // $contributorIds = ['gcihs', 'local'];
            // $contributorFilters = array('terms' => ['item.contributor-id' => $contributorIds]);
        }

        return $contributorFilters;
    }

    protected function constructHighlightParams($viewId)
    {
        $highlight = array();

        if ($viewId == SearchResultsViewFactory::TABLE_VIEW_ID)
        {
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
        }

        return $highlight;
    }

    protected function constructMustQueryParams($terms)
    {
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
        return $mustQuery;
    }

    protected function constructQueryFilters($public, $fileFilter, array $roots, array $leafs)
    {
        $queryFilters = $this->avantElasticsearchFacets->getFacetFilters($roots, $leafs);

        if ($public)
        {
            // Filter results to only contain public items.
            $queryFilters[] = array('term' => ['item.public' => true]);
        }

        if ($fileFilter == 1)
        {
            // Filter results to only contain items that have a file attached and thus have an image.
            $queryFilters[] = array('exists' => ['field' => "url.image"]);
        }

        return $queryFilters;
    }

    protected function constructShouldQueryParams()
    {
        $shouldQuery = [
            "match" => [
                "element.type" => [
                    "query" => "reference",
                    "boost" => 2
                ]
            ]
        ];
        return $shouldQuery;
    }

    protected function constructSourceFields($viewId, $indexId)
    {
        $fields = array();

        // Specify which fields the query will return.
        if ($viewId == SearchResultsViewFactory::TABLE_VIEW_ID)
        {
            $fields = [
                'element.*',
                'item.*',
                'file.*',
                'tags',
                'html-fields',
                'pdf.file-name',
                'pdf.file-url',
                'url.*'
            ];
        }
        else if ($viewId == SearchResultsViewFactory::IMAGE_VIEW_ID)
        {
            $fields = [
                'element.title',
                'element.identifier',
                'item.*',
                'file.*',
                'url.*'
            ];
        }
        else if ($viewId == SearchResultsViewFactory::INDEX_VIEW_ID)
        {
            $indexFieldName = 'creator';
            $fields = [
                'element.' . $indexFieldName,
                'item.id'
            ];
        }
        else if ($viewId == SearchResultsViewFactory::TREE_VIEW_ID)
        {
        }

        return $fields;
    }

    public function constructSuggestQueryParams($fuzziness, $size)
    {
        // Note that skip_duplicates is false to ensure that all the right values are returned.
        // The Elasticsearch documentation also says that performance is better when false.
        $query = [
            '_source' => [
                'item.title'
            ],
            'suggest' => [
                'keywords-suggest' => [
                    'prefix' => '%s',
                    'completion' => [
                        'field' => 'suggestions',
                        'skip_duplicates' => false,
                        'size' => $size,
                        'fuzzy' =>
                            [
                                'fuzziness' => $fuzziness
                            ]
                    ]
                ]
            ]
        ];

        return json_encode($query);
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