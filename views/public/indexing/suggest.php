<?php
    // Get the text to show suggestions for.
    $prefix = isset($_REQUEST['term']) ? $_REQUEST['term'] : '';

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
    $usingSharedIndex = $avantElasticsearchQueryBuilder->isUsingSharedIndex();

    // Create an array of title/link pairs so that when the user chooses a suggestion they are effectively clicking
    // a link to search for their selection (as opposed to filling the search textbox with the suggestion text).
    foreach ($titles as $title)
    {
        $value = url('find?query=' . urlencode($title));
        if ($usingSharedIndex)
        {
            // Add a query string arg to indicate that this link is for the shared index. Without the arg, we'd
            // have to rely on cookies to know, but a user could have cookies disabled,
            $value .= '&all=on';
        }
        $suggestions[] = (object) array('label' => $title, 'value' => $value);
    }

    // Return the suggestions as JSON in response to the autocomplete request.
    echo json_encode($suggestions);

