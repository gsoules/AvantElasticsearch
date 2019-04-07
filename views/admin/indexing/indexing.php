<?php
ini_set('max_execution_time', 300);

$pageTitle = __('Elasticsearch Indexing');
echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

echo '<h4>' . __('Indexer') . '</h4>';

if (isset($_REQUEST['export']))
{
    $export = (bool) $_REQUEST['export'];
    $limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 25;
    $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
    $responses = $avantElasticsearchIndexBuilder->indexAll($export, $limit);
    $message = $avantElasticsearchIndexBuilder->convertResponsesToMessageString($responses);

    if (empty($message))
    {
        echo '<p>' . 'SUCCESS' . '</p>';
    }
    else
    {
        echo '<p>' . 'ERRORS' . '</p>';
        echo '<p>' . $message . '</p>';
    }
}

echo foot();
?>
