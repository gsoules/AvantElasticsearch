<?php
ini_set('max_execution_time', 1200);

$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
$avantElasticserachClient = new AvantElasticsearchClient();

$health = $avantElasticserachClient->getHealth();
$healthReport = $health['message'];
$healthReportClass = ' class="health-report-' . ($health['ok'] ? 'ok' : 'error') . '"';

$pageTitle = __('Elasticsearch Indexing');
$operation = isset($_REQUEST['operation']) ? $_REQUEST['operation'] : 'none';
$limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100;
$options = array('none' => 'No Action', 'import' =>'Import', 'export' => 'Export');
$action = url("elasticsearch/indexing?operation=$operation&limit=$limit");
$filename = $avantElasticsearchIndexBuilder->getindexDataFilename();

echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));
echo "<div$healthReportClass>$healthReport</div>";
echo "<hr/>";
echo "<form id='indexing-form' name='indexing-form' action='$action' method='get'>";
echo '<div class="indexing-radio-buttons">';
echo $this->formRadio('operation', $operation, null, $options);
echo '</div>';
echo '<div class="">Limit: ';
echo $this->formText('limit', $limit, array('size' => '4', 'id' => 'limit'));
echo '</div>';
echo "<button id='submit_index' 'type='submit' value='Index'>Start Indexing</button>";
echo '</form>';
echo "<div>$filename</div>";

$mb = 1048576;
$mem1 = intval(memory_get_usage() / $mb);
$message = '';

if ($operation != 'none')
{
    $export = $operation == 'export';
    $deleteExistingIndex = true;

    if ($export)
    {
        $avantElasticsearchIndexBuilder->performBulkIndexExport($filename, $limit);
    }
    else
    {
        $avantElasticsearchIndexBuilder->performBulkIndexImport($filename, $deleteExistingIndex);
    }

    $responses = $avantElasticsearchIndexBuilder->performBulkIndex($export, $deleteExistingIndex, $limit);
    $message = $avantElasticsearchIndexBuilder->convertResponsesToMessageString($responses);
}

$executionTime = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);
$mem2 = intval(memory_get_usage() / $mb);
$used = intval($mem2 - $mem1);
$peak = intval(memory_get_peak_usage() /  $mb);

echo '<div>';
echo "Start/End usage: $mem1 MB / $mem2 MB</br>";
echo "Memory used: $used MB</br>";
echo "Peak usage: $peak MB</br>";
echo '</div>';

if ($operation != 'none')
{
    echo '<hr/>';
    echo "<div>$options[$operation] execution time: $executionTime seconds</div>";
    if (empty($message))
    {
        echo "<p>SUCCESS</p>";
    }
    else
    {
        echo "<p>ERRORS</p>";
        echo "<p>$message</p>";
    }
}

echo foot();
?>
