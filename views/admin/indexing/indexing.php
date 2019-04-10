<?php
ini_set('max_execution_time', 1000);

$pageTitle = __('Elasticsearch Indexing');
echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

echo '<h4>' . __('Indexer') . '</h4>';

if (isset($_REQUEST['export']))
{
    $export = (bool) $_REQUEST['export'];
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 25;
    $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

    $mem1 = memory_get_usage();
    $responses = $avantElasticsearchIndexBuilder->indexAll($export, $limit);
    $mem2 = memory_get_usage();

    $mb = 1048576;
    $used = intval(($mem2 - $mem1) / $mb);
    $current = intval($mem2 /  $mb);
    $peak = intval(memory_get_peak_usage() /  $mb);

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
    echo "<p>Current usage: $current MB</p>";
    echo "<p>Peak usage: $peak MB</p>";
}

echo foot();
?>
