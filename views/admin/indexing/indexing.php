<?php
ini_set('max_execution_time', 300);

$pageTitle = __('ELasticsearch Indexing');
echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

echo '<h4>' . __('Indexer') . '</h4>';

////////////////
$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
$responses = $avantElasticsearchIndexBuilder->indexAll();
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

echo foot();
?>
