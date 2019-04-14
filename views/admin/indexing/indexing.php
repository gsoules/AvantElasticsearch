<?php
ini_set('max_execution_time', 1200);

$pageTitle = __('Elasticsearch Indexing');
echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

echo '<h4>' . __('Indexer') . '</h4>';

if (isset($_REQUEST['export']))
{
    $export = (bool) $_REQUEST['export'];
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 25;
    $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

    $mb = 1048576;
    $mem1 = intval(memory_get_usage() / $mb);

    // Perform indexing.
    $responses = $avantElasticsearchIndexBuilder->indexAll($export, $limit);

    $mem2 = intval(memory_get_usage() / $mb);

    $used = intval($mem2 - $mem1);
    $peak = intval(memory_get_peak_usage() /  $mb);

    $executionTime = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);

    $message = $avantElasticsearchIndexBuilder->convertResponsesToMessageString($responses);

    if (empty($message))
    {
        echo "<p>SUCCESS</p>";
    }
    else
    {
        echo "<p>ERRORS</p>";
        echo "<p>$message</p>";
    }

    echo "<p>Memory used: $used MB</p>";
    echo "<p>Start/End usage: $mem1 MB / $mem2 MB</p>";
    echo "<p>Peak usage: $peak MB</p>";
    echo "<p>Execution time: $executionTime seconds</p>";
}

if (isset($_REQUEST['suggest']))
{
    $query = $_REQUEST['suggest'];
    $params = [
        'index' => 'omeka',
        'body' => [
            '_source' => [
              'suggestions'
            ],
            'suggest' => [
                'keywords-suggest' => [
                    'prefix' => $query,
                    'completion' => [
                        'field' => 'suggestions',
                        'skip_duplicates' => true,
                        'size' => 5,
                        'fuzzy' =>
                        [
                            'fuzziness' => 0
                        ]
                    ]
                ]
            ]
        ]
    ];

    $avantElasticsearchClient = new AvantElasticsearchClient();
    $response = $avantElasticsearchClient->search($params);

    $suggestions = array();

    foreach ($response["suggest"]["keywords-suggest"][0]["options"] as $option)
    {
        $suggestions[] = $option['text'];
    }

    foreach ($suggestions as $suggestion)
    {
        echo "<p>$suggestion</p>";
    }

    return;
}

echo foot();
?>
