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

    protected function constructAggregationsParams($viewId, $indexId, $sharedSearchingEnabled)
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
            else if ($definition['shared'] && !$sharedSearchingEnabled)
            {
                // This facet is only used when showing shared results.
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

    protected function constructContributorFilters($sharedSearchingEnabled)
    {
        $contributorFilters = array();

        if ($sharedSearchingEnabled)
        {
            // This is where we can add support to only display results from specific contributors. Somehow the
            // $contributorIds array needs to get populated with selections the user has made to indicate which contributors
            // they want to see results from. To show results from all contributors, return an empty array;

            // $contributorIds = ['gcihs', 'local'];
            // $contributorFilters = array('terms' => ['item.contributor-id' => $contributorIds]);
        }

        return $contributorFilters;
    }

    public function constructFileStatisticsAggregationParams($indexName)
    {
        $params = [
            'index' => $indexName,
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
                                'number_of_fragments' => 4,
                                'fragment_size' => 150,
                                'pre_tags' => ['<span class="hit-highlight">'],
                                'post_tags' => ['</span>']
                            ],
                        'pdf.text-*' =>
                            (object)[
                                'number_of_fragments' => 4,
                                'fragment_size' => 150,
                                'pre_tags' => ['<span class="hit-highlight">'],
                                'post_tags' => ['</span>']
                            ]
                    ]
                ];
        }

        return $highlight;
    }

    protected function constructQueryCondition($fieldName, $condition, $terms)
    {
        switch ($condition)
        {
            case 'is exactly':
                $query = array('term' => ["element.$fieldName.lowercase" => $terms]);
                break;

            case 'is empty':
                // Handled by the constuctQueryMustNotExist method.
                break;

            case 'is not empty':
                $query = array('exists' => ['field' => "element.$fieldName"]);
                break;

            case 'starts with':
                $query = array('prefix' => ["element.$fieldName.lowercase" => $terms]);
                break;

            case 'ends with':
                $query = array('wildcard' => ["element.$fieldName.lowercase" => "*$terms"]);
                break;

            case 'matches':
                $query = array('regexp' => ["element.$fieldName.lowercase" => $terms]);
                break;

            default:
                // 'contains'
                $query = array(
                    'simple_query_string' => [
                        'query' => $terms,
                        'default_operator' => 'and',
                        'fields' => [
                            "element.$fieldName"
                        ]
                    ]
                );
        }

        return $query;
    }

    protected function constructQueryFilters($public, array $roots, array $leafs, $sharedSearchingEnabled)
    {
        // Get filters that are already set for applied facets.
        $queryFilters = $this->avantElasticsearchFacets->getFacetFilters($roots, $leafs);

        // Filter results to only contain public items.
        if ($public)
            $queryFilters[] = array('term' => ['item.public' => true]);

        // Filter results to only contain items that have a file attached and thus have an image.
        if (intval(AvantCommon::queryStringArg('filter')) == 1)
            $queryFilters[] = array('exists' => ['field' => "url.image"]);

        // Construct a query for each Advanced Search filter.
        $advancedFilters = $this->getAdvancedFilters();
        foreach ($advancedFilters as $advanced)
        {
            $condition = $this->getAdvancedCondition($advanced);
            if (empty($condition) || $condition == 'is empty')
                continue;

            $fieldName = $this->getAdvancedFieldName($advanced);
            $terms = $this->getAdvancedTerms($advanced);

            $queryFilters[] = $this->constructQueryCondition($fieldName, $condition, $terms);
        }

        // Create filters to limit results to specific contributors.
        $contributorFilters = $this->constructContributorFilters($sharedSearchingEnabled);
        if (!empty($contributorFilters))
            $queryFilters[] = $contributorFilters;

        return $queryFilters;
    }

    protected function constructQueryMust($terms, $fuzzy)
    {
        if (empty($terms))
        {
            $mustQuery = ['match_all' => (object)[]];
        }
        else
        {
            if ($fuzzy)
            {
                // Determine if the request if for a phrase match -- the terms are wrapped in double quotes.
                $phraseMatch = strpos($terms, '"') === 0 && strrpos($terms, '"') === strlen($terms) - 1;

                if ($phraseMatch)
                {
                    // Append '~1' to the end of the phrase to add a slop value of 1.
                    $terms .= '~1';
                }
                else
                {
                    // Remove all non alphanumeric characters from the terms and create an array of unique keywords.
                    $cleanText = preg_replace('/[^a-z\d" ]/i', '', $terms);
                    $parts = explode(' ', $cleanText);
                    $parts = array_unique($parts);

                    // Append '~1' to the end of each keyword to enable fuzziness with an edit distance of 1.
                    foreach ($parts as $part)
                    {
                        if (empty($part))
                            continue;
                        $terms = str_replace($part, "$part~1", $terms);
                    }
                }

            }

            $mustQuery = [
                'simple_query_string' => [
                    'query' => $terms,
                    'default_operator' => 'and',
                    'fields' => [
                        'item.title^15',
                        'element.*',
                        'tags',
                        'pdf.text-*'
                    ]
                ]
            ];
        }

        return $mustQuery;
    }

    protected function constructQueryMustNotExists()
    {
        // This method is used only to support the Is Empty filter for Advanced Search. Unlike Is Not Empty which is
        // implemented using an Elasticsearch query filter, Is Empty is implemented in the must_not portion of the query.
        $mustNot = array();

        $advancedFilters = $this->getAdvancedFilters();
        foreach ($advancedFilters as $advanced)
        {
            if ($this->getAdvancedCondition($advanced) != 'is empty')
                continue;

            $fieldName = $this->getAdvancedFieldName($advanced);

            $mustNot[] = [
                "exists" => [
                    "field" => "element.$fieldName"
                ]
            ];
        }

        return $mustNot;
    }

    protected function constructQueryShould()
    {
        $shouldQuery = [
            "match" => [
                "element.type" => [
                    "query" => "reference",
                    "boost" => 5
                ]
            ]
        ];

        return $shouldQuery;
    }

    public function constructSearchQuery($query, $limit, $sort, $public, $sharedSearchingEnabled, $fuzzy)
    {
        // Get parameter values or defaults.
        $leafs = isset($query[FACET_KIND_LEAF]) ? $query[FACET_KIND_LEAF] : [];
        $page = isset($query['page']) ? $query['page'] : 1;
        $offset = ($page - 1) * $limit;
        $roots = isset($query[FACET_KIND_ROOT]) ? $query[FACET_KIND_ROOT] : [];
        $viewId = isset($query['view']) ? $query['view'] : SearchResultsViewFactory::TABLE_VIEW_ID;
        $indexId = isset($query['index']) ? $query['index'] : 'Title';

        // Get keywords that were specified on the Advanced Search page.
        $terms = isset($query['keywords']) ? $query['keywords'] : '';

        // Check if keywords came from the Simple Search text box.
        if (empty($terms))
            $terms = isset($query['query']) ? $query['query'] : '';

        // Specify which fields the query will return.
        $body['_source'] = $this->constructSourceFields($viewId, $indexId);

        // Construct the actual query.
        $body['query']['bool']['must'] = $this->constructQueryMust($terms, $fuzzy);
        $body['query']['bool']['should'] = $this->constructQueryShould();

        $mustNot = $this->constructQueryMustNotExists();
        if (!empty($mustNot))
            $body['query']['bool']['must_not'] = $mustNot;

        // Create filters that will limit the query results.
        $queryFilters = $this->constructQueryFilters($public, $roots, $leafs, $sharedSearchingEnabled);
        if (count($queryFilters) > 0)
            $body['query']['bool']['filter'] = $queryFilters;

        // Construct the aggregations that will provide facet values.
        $body['aggregations'] = $this->constructAggregationsParams($viewId, $indexId, $sharedSearchingEnabled);

        // Specify which fields will have hit highlighting.
        $highlightParams = $this->constructHighlightParams($viewId);
        if (!empty($highlightParams))
            $body['highlight'] = $highlightParams;

        // Specify if sorting by column. If not, sorting is by relevance based on score.
        if (!empty($sort))
            $body['sort'] = $sort;

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
        else if ($viewId == SearchResultsViewFactory::GRID_VIEW_ID)
        {
            $fields = [
                'element.title',
                'element.identifier',
                'element.type',
                'element.subject',
                'item.*',
                'file.*',
                'url.*'
            ];
        }
        else if ($viewId == SearchResultsViewFactory::INDEX_VIEW_ID)
        {
            $indexFieldName = $this->convertElementNameToElasticsearchFieldName($indexId);
            $fields = [
                'element.' . $indexFieldName,
                'element.identifier',
                'url.item'
            ];
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

    protected function getAdvancedCondition($advanced)
    {
        return isset($advanced['type']) ? $advanced['type'] : '';
    }

    protected function getAdvancedFieldName($advanced)
    {
        $elementId = isset($advanced['element_id']) ? $advanced['element_id'] : '';
        if (ctype_digit($elementId))
        {
            // The value is an Omeka element Id. Attempt to get the element's name.
            $elementName = ItemMetadata::getElementNameFromId($elementId);
        }
        else
        {
            // The value is an Omeka element name.
            $elementName = $elementId;
        }

        return $this->convertElementNameToElasticsearchFieldName($elementName);
    }

    protected function getAdvancedFilters()
    {
        return isset($_GET['advanced']) ? $_GET['advanced'] : array();
    }

    protected function getAdvancedTerms($advanced)
    {
        return isset($advanced['terms']) ? $advanced['terms'] : '';
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