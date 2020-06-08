<?php
class AvantElasticsearchQueryBuilder extends AvantElasticsearch
{
    protected $avantElasticsearchFacets;
    protected $synonyms;
    protected $usingSharedIndex;

    public function __construct()
    {
        parent::__construct();

        $this->usingSharedIndex = AvantElasticsearch::useSharedIndexForQueries();
        $indexName = $this->usingSharedIndex ? self::getNameOfSharedIndex() : self::getNameOfLocalIndex();
        $this->setIndexName($indexName);

        $this->avantElasticsearchFacets = new AvantElasticsearchFacets();
    }

    protected function constructAggregationsParams($sharedSearchingEnabled)
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
                    ],
                    'contributor-ids' => [
                        'terms' => [
                            'field' => 'item.contributor-id'
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
                        'common.description' =>
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
        if (empty($terms) && !($condition == 'is empty' || $condition == 'is not empty'))
            return '';

        if (empty($fieldName))
            return '';

        if ($fieldName == 'tags')
        {
            $field = 'tags';
            if ($condition == 'is exactly')
            {
                // The 'is exactly' condition won't work with tags because its value is an array, not a string.
                $condition = 'contains';
            }
        }
        else if ($fieldName == 'contributor')
        {
            $field = 'item.contributor-id';
        }
        else if ($fieldName == 'public')
        {
            $field = 'item.public';
            $terms = strtolower(trim($terms));
            if ($terms != 'true' && $terms != 'false')
                $terms = 'false';
        }
        else
            $field = $this->getQualifiedFieldNameFor($fieldName);

        $fieldLowerCase = "$field.lowercase";

        switch ($condition)
        {
            case 'is exactly':
                $query = array('term' => [$fieldLowerCase => $terms]);
                break;

            case 'is empty':
                // Handled by the constructQueryMustNotExists method.
                break;

            case 'is not empty':
                $query = array('exists' => ['field' => $field]);
                break;

            case 'starts with':
                $query = array('prefix' => [$fieldLowerCase => $terms]);
                break;

            case 'ends with':
                $query = array('wildcard' => [$fieldLowerCase => "*$terms"]);
                break;

            case 'matches':
                $query = array('regexp' => [$fieldLowerCase => $terms]);
                break;

            default:
                // 'contains'
                $query = array(
                    'simple_query_string' => [
                        'query' => $terms,
                        'default_operator' => 'and',
                        'fields' => [
                            $field
                        ]
                    ]
                );
        }

        return $query;
    }

    protected function constructQueryFilters($queryArgs, $public, $roots, $leafs, $sharedSearchingEnabled)
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
        $advancedQueryArgs = isset($queryArgs['advanced']) ? $queryArgs['advanced'] : array();

        foreach ($advancedQueryArgs as $advancedArg)
        {
            $condition = $this->getAdvancedCondition($advancedArg);
            if (empty($condition) || $condition == 'is empty')
                continue;

            $fieldName = $this->getAdvancedFieldName($advancedArg);
            $terms = $this->getAdvancedTerms($advancedArg);

            $conditionFilter = $this->constructQueryCondition($fieldName, $condition, $terms);
            if (!empty($conditionFilter))
                $queryFilters[] = $conditionFilter;
        }

        // Create year range filter.
        $yearFilter = $this->constructYearFilter();
        if (!empty($yearFilter))
            $queryFilters[] = $yearFilter;

        // Create tags filter.
        $tagsFilter = $this->constructTagsFilter();
        if (!empty($tagsFilter))
            $queryFilters[] = $tagsFilter;

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
                // Determine if the request is for a phrase match -- the terms are wrapped in double quotes.
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
                    $words = explode(' ', $cleanText);
                    $words = array_unique($words);

                    // Edit the search terms to increase the chances of getting results from the query.
                    foreach ($words as $word)
                    {
                        // Ignore empty words and numbers.
                        if (empty($word) || ctype_digit($word))
                            continue;

                        // See if there is a synonym for this word.
                        $synonym = $this->getFuzzySynonym($word);

                        if (empty($synonym))
                        {
                            // Append '~1' to the end of the keyword to enable fuzziness with an edit distance of 1.
                            $replacement = "$word~1";
                        }
                        else
                        {
                            $replacement = $synonym;
                        }

                        $terms = str_replace($word, $replacement, $terms);
                    }
                }
            }

            $mustQuery = [
                'simple_query_string' => [
                    'query' => $terms,
                    'default_operator' => 'and',
                    'fields' => [
                        'item.title^20',
                        'item.description^10',
                        'core-fields.*',
                        'local-fields.*',
                        'tags',
                        'pdf.text-*'
                    ]
                ]
            ];

            $searchPrivateFields = !empty(current_user());
            if ($searchPrivateFields)
            {
                $mustQuery["simple_query_string"]["fields"][] = 'private.*';
            }
        }

        return $mustQuery;
    }

    protected function constructQueryMustNotExists($queryArgs)
    {
        // This method is used only to support the Is Empty filter for Advanced Search. Unlike Is Not Empty which is
        // implemented using an Elasticsearch query filter, Is Empty is implemented in the must_not portion of the query.

        $mustNot = array();

        $advancedFilters = isset($queryArgs['advanced']) ? $queryArgs['advanced'] : array();

        foreach ($advancedFilters as $advanced)
        {
            if ($this->getAdvancedCondition($advanced) != 'is empty')
                continue;

            $fieldName = $this->getAdvancedFieldName($advanced);
            if ($fieldName == 'tags')
                $field = 'tags';
            else if ($fieldName == 'contributor')
                $field = 'item.contributor-id';
            else
                $field = $this->getQualifiedFieldNameFor($fieldName);

            $mustNot[] = [
                "exists" => [
                    "field" => $field
                ]
            ];
        }

        return $mustNot;
    }

    protected function constructQueryShould()
    {
        $shouldQuery = [
            "match" => [
                "common.type" => [
                    "query" => "reference",
                    "boost" => 5
                ]
            ]
        ];

        return $shouldQuery;
    }

    public function constructSearchQuery($queryArgs, $limit, $sort, $indexElementName, $public, $sharedSearchingEnabled, $fuzzy)
    {
        // Get parameter values or defaults.
        $leafs = isset($queryArgs[AvantElasticsearchFacets::FACET_KIND_LEAF]) ? $queryArgs[AvantElasticsearchFacets::FACET_KIND_LEAF] : [];

        // Verify that the page arg is valid.
        $page = isset($queryArgs['page']) ? intval($queryArgs['page']) : 1;
        $page = $page == 0 ? 1 : abs($page);
        $offset = ($page - 1) * $limit;

        // Prevent the "Result window is too large" error from occurring if query string value for page is too large.
        if ($offset + $limit > AvantSearch::MAX_SEARCH_RESULTS)
        {
            $offset = AvantSearch::MAX_SEARCH_RESULTS - $limit;
        }

        $roots = isset($queryArgs[AvantElasticsearchFacets::FACET_KIND_ROOT]) ? $queryArgs[AvantElasticsearchFacets::FACET_KIND_ROOT] : [];
        $viewId = isset($queryArgs['view']) ? $queryArgs['view'] : SearchResultsViewFactory::TABLE_VIEW_ID;

        // Get keywords that were specified on the Advanced Search page.
        $terms = isset($queryArgs['keywords']) ? $queryArgs['keywords'] : '';

        // Check if keywords came from the Simple Search text box.
        if (empty($terms))
            $terms = isset($queryArgs['query']) ? $queryArgs['query'] : '';

        // Specify which fields the query will return.
        $body['_source'] = $this->constructSourceFields($viewId, $this->convertElementNameToElasticsearchFieldName($indexElementName));

        if (strpos($terms, '::') === 0 && !empty(current_user()))
        {
            // Construct an admin query using Elasticsearch Query String Syntax.
            // This feature is restricted to logged in users because it searches all data including non-public items and private elements.
            // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html#query-string-syntax
            // Examples:
            //    ::file.total:>10 = find items that have more than 10 files attached to them
            //    ::item.public:false AND private.status:ok = find non-public items that have a status of 'ok'
            //    ::url.cover:true = find items that are using a cover image
            $q = substr($terms, 2);
            $body['query'] = array('query_string' => ['query' => $q]);
        }
        else
        {
            // Construct the bool query used for user searches.
            $body['query']['bool']['must'] = $this->constructQueryMust($terms, $fuzzy);
            $body['query']['bool']['should'] = $this->constructQueryShould();

            $mustNot = $this->constructQueryMustNotExists($queryArgs);
            if (!empty($mustNot))
                $body['query']['bool']['must_not'] = $mustNot;

            // Create filters that will limit the query results.
            $queryFilters = $this->constructQueryFilters($queryArgs, $public, $roots, $leafs, $sharedSearchingEnabled);
            if (count($queryFilters) > 0)
                $body['query']['bool']['filter'] = $queryFilters;

            // Specify which fields will have hit highlighting.
            $highlightParams = $this->constructHighlightParams($viewId);
            if (!empty($highlightParams))
                $body['highlight'] = $highlightParams;
        }

        // Construct the aggregations that will provide facet values.
        $body['aggregations'] = $this->constructAggregationsParams($sharedSearchingEnabled);

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

    protected function constructSourceFields($viewId, $indexFieldName)
    {
        $fields = array();

        // Specify which fields the query will return.
        if ($viewId == SearchResultsViewFactory::TABLE_VIEW_ID)
        {
            $fields = [
                'core-fields.*',
                'local-fields.*',
                'private-fields.*',
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
            // Include the Type and Subject for the logic that chooses the proper placeholder image (based on item
            // type and subject) when the item has no image of its own).
            $fields = [
                'core-fields.title',
                'core-fields.identifier',
                'core-fields.type',
                'core-fields.subject',
                'item.*',
                'file.*',
                'url.*'
            ];
        }
        else if ($viewId == SearchResultsViewFactory::INDEX_VIEW_ID)
        {
            $fields = [
                'core-fields.' . $indexFieldName,
                'local-fields.' . $indexFieldName,
                'private-fields.' . $indexFieldName,
                'common.identifier',
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

    protected function constructTagsFilter()
    {
        // This methods supports Digital Archive 2.0 where 'tags' was a separate query string arg instead of an
        // Advanced Search pseudo element.

        $tags = AvantCommon::queryStringArg('tags', '');

        if (empty($tags))
            return '';

        $query = array(
            'simple_query_string' => [
                'query' => $tags,
                'default_operator' => 'and',
                'fields' => [
                    'tags'
                ]
            ]
        );

        return $query;
    }

    protected function constructYearFilter()
    {
        $yearStart = AvantCommon::queryStringArg('year_start', 0);
        $yearEnd = AvantCommon::queryStringArg('year_end', 0);

        if (empty($yearStart) && empty($yearEnd))
            return '';

        $query = array(
            'range' => [
                'item.year' => [
                ]
            ]
        );

        if ($yearStart >= 0)
            $query['range']['item.year']['gte'] = $yearStart;

        if ($yearEnd >= 0 && $yearEnd >= $yearStart)
            $query['range']['item.year']['lte'] = $yearEnd;

        return $query;
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
            // The value is an Omeka element Id. Attempt to get the element's name. This should only happen if the query
            // args come from a link that was generated by a previous version of the Digital Archive, before it used
            // Elasticsearch. Chances are that the element Id will map to the right element name, but if not, this method
            // will return an empty field name (or possibly a wrong field name) which will result in incorrect query
            // results, but otherwise no harm will be done.
            $elementName = ItemMetadata::getElementNameFromId($elementId);
        }
        else
        {
            // The value is an Omeka element name.
            $elementName = $elementId;
        }

        return $this->convertElementNameToElasticsearchFieldName($elementName);
    }

    protected function getAdvancedTerms($advanced)
    {
        return isset($advanced['terms']) ? trim($advanced['terms']) : '';
    }

    public function getFacetDefinitions()
    {
        return $this->avantElasticsearchFacets->getFacetDefinitions();
    }

    protected function getFuzzySynonym($word)
    {
        // Replace common abbreviations with synonyms. If this list becomes long, consider using
        // Elasticsearch's Synonym Token Filter mechanism. For now this is simple and fast.
        // Returns a string containing the original word ORed with the synonym e.g. '(rd|road)'.

        $word = strtolower($word);

        if (!isset($this->synonyms))
        {
            $config = AvantElasticsearch::getAvantElasticsearcConfig();
            $this->synonyms = $config ? $config-> synonyms : array();
        }

        foreach ($this->synonyms as $pair)
        {
            $parts = array_map('trim', explode(',', $pair));
            if (count($parts) != 2)
                continue;

            if ($parts[0] == $word)
                return "($parts[0]|$parts[1])";
        }

        return '';
    }

    protected function getQualifiedFieldNameFor($fieldName)
    {
        if (in_array($fieldName, $this->getFieldNamesOfCommonElements()))
            return "core-fields.$fieldName";
        else if (in_array($fieldName, $this->getFieldNamesOfLocalElements()))
            return "local-fields.$fieldName";
        else if (in_array($fieldName, $this->getFieldNamesOfPrivateElements()))
            return "private-fields.$fieldName";
        else
            return $fieldName;
    }

    public function isUsingSharedIndex()
    {
        return $this->usingSharedIndex;
    }
}