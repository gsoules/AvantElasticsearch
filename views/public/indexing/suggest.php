<?php
    $executionSeconds0 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

    // Get the text to show suggestions for.
    $prefix = isset($_REQUEST['query']) ? $_REQUEST['query'] : '';

    // Specify how many suggestions to return.
    $maxRequests = 7;

    $avantElasticsearchClient = new AvantElasticsearchClient();
    if (!$avantElasticsearchClient->ready())
    {
        // This should never happen, but if it does, simply return no suggestions.
        return '';
    }

    $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();
    $avantElasticsearchSuggest = new AvantElasticsearchSuggest();

    $prefix = $avantElasticsearchSuggest->stripPunctuation($prefix);

    $params = $avantElasticsearchQueryBuilder->constructSuggestQueryParams($prefix, false, $maxRequests);
    $start =  microtime(true);
    $rawSuggestions = $avantElasticsearchClient->suggest($params);
    $end =  microtime(true);
    $executionSeconds1 = $end - $start;

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
    $usingSharedIndex = $avantElasticsearchQueryBuilder->isUsingSharedIndex();

    // Create an array of title/link pairs so that when the user chooses a suggestion they are effectively clicking
    // a link to search for their selection (as opposed to filling the search textbox with the suggestion text).
    foreach ($titles as $title)
    {
        // Some items have multiple titles, but suggest only the first.
        $parts = explode(ES_DOCUMENT_EOL, $title);
        $firstTitle = $parts[0];

        $value = url('find?query=' . urlencode($firstTitle));
        if ($usingSharedIndex)
        {
            // Add a query string arg to indicate that this link is for the shared index. Without the arg, we'd
            // have to rely on cookies to know, but a user could have cookies disabled,
            $value .= '&all=on';
        }
        $suggestions[] = (object) array('label' => $firstTitle, 'value' => $value);
    }

    $executionSeconds2 = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
    $suggestions[] = (object) array('label' => $executionSeconds0, 'value' => '');
    $suggestions[] = (object) array('label' => $executionSeconds1, 'value' => '');
    $suggestions[] = (object) array('label' => $executionSeconds2, 'value' => '');

    // Return the suggestions as JSON in response to the autocomplete request.
    echo json_encode($suggestions);


