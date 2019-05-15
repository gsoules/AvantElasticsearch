<?php
    $prefix = isset($_REQUEST['term']) ? $_REQUEST['term'] : '';

    // Specify how many suggestions to return.
    $maxRequests = 7;

    $avantElasticsearchClient = new AvantElasticsearchClient();
    if (!$avantElasticsearchClient->ready())
    {
        // This should never happen, but if it does, simply return no suggestions.
        return '';
    }

    // Determine if the user is searching all sites.
    $showAll = isset($_COOKIE['SEARCH-ALL']) ? $_COOKIE['SEARCH-ALL'] == 'true' : false;

    $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();
    if ($showAll)
        $indexName = $avantElasticsearchQueryBuilder->getIndexNameForSharing();
    else
        $indexName = $avantElasticsearchQueryBuilder->getIndexNameForContributor();
    $avantElasticsearchQueryBuilder->setIndexName($indexName);
    $avantElasticsearchSuggest = new AvantElasticsearchSuggest();

    $prefix = $avantElasticsearchSuggest->stripPunctuation($prefix);

    $params = $avantElasticsearchQueryBuilder->constructSuggestQueryParams($prefix, false, $maxRequests);
    $rawSuggestions = $avantElasticsearchClient->suggest($params);

    if (empty($rawSuggestions))
    {
        // Add fuzziness to see if that will get some results.
        $params = $avantElasticsearchQueryBuilder->constructSuggestQueryParams($prefix, true, $maxRequests);
        $rawSuggestions = $avantElasticsearchClient->suggest($params);
    }

    $suggestions = array();

    $titles = array();
    foreach ($rawSuggestions as $rawSuggestion)
    {
        $titles[] = $rawSuggestion["_source"]["item"]["title"];
    }

    // Remove any duplicates. It's safer to do it here than by using the Elasticsearch skip_duplicates option.
    $titles = array_unique($titles);

    // Create an array of title/link pairs so that when the user chooses a suggestion they are effectively
    // clicking a link to search for their selection.
    foreach ($titles as $title)
    {
        $value = url('find?query=' . urlencode($title));
        if ($showAll)
        {
            $value .= '&all=on';
        }
        $suggestions[] = (object) array('label' => $title, 'value' => $value);
    }

    // Return the suggestions as JSON in response to the autocomplete request.
    echo json_encode($suggestions);

