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

    $titles = array();
    foreach ($options as $option)
    {
        $titles[] = $option["_source"]["title"];
    }

    // Remove any duplicates. It's safer to do it here than by using the Elasticsearch skip_duplicates option.
    $titles = array_unique($titles);

    // Create an array of title/link pairs so that when the user chooses a suggestion they are effectively
    // clicking a link to search for their selection.
    foreach ($titles as $title)
    {
        $value = url('find?query=' . urlencode($title));
        $suggestions[] = (object) array('label' => $title, 'value' => $value);
    }

    // Return the suggestions as JSON in response to the autocomplete request.
    echo json_encode($suggestions);

