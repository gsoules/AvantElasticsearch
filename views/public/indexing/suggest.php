<?php
    $prefix = isset($_REQUEST['term']) ? $_REQUEST['term'] : '';

    $maxRequests = 6;

    $avantElasticsearchClient = new AvantElasticsearchClient();
    $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();
    $avantElasticsearchSuggest = new AvantElasticsearchSuggest();

    $prefix = $avantElasticsearchSuggest->stripPunctuation($prefix);

    $params = $avantElasticsearchQueryBuilder->constructSuggestQueryParams($prefix, false, $maxRequests);
    $options = $avantElasticsearchClient->suggest($params);

    if (empty($options))
    {
        // Add fuzziness to see if that will get some results.
        $params = $avantElasticsearchQueryBuilder->constructSuggestQueryParams($prefix, true, $maxRequests);
        $options = $avantElasticsearchClient->suggest($params);
    }

    $suggestions = array();

    foreach ($options as $option)
    {
        $suggestions[] = $option["_source"]["title"];
    }

    // Remove any duplicates. It's safer to do it here than by using the Elasticsearch skip_duplicates option.
    $suggestions = array_unique($suggestions);

    echo json_encode($suggestions);

