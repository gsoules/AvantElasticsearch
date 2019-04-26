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
$fileDefault =  date('md') . '-' . ElasticsearchConfig::getOptionValueForContributorId();
$file = isset($_REQUEST['file']) ? $_REQUEST['file'] : $fileDefault;

$options = array(
    'none' => 'No Action',
    'import_new' =>'Import into new index',
    'import_update' =>'Import into existing index',
    'export_all' => 'Export all items from Omeka',
    'export_limit' => 'Export limited items from Omeka'
);

$action = url("elasticsearch/indexing?operation=$operation&limit=$limit");
$mb = 1048576;
$mem1 = intval(memory_get_usage() / $mb);
$message = '';

echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));
echo "<div$healthReportClass>$healthReport</div>";

if ($avantElasticserachClient->ready())
{
    echo "<hr/>";
    echo "<form id='indexing-form' name='indexing-form' action='$action' method='get'>";
    echo '<div class="indexing-radio-buttons">';
    echo $this->formRadio('operation', 'none', null, $options);
    echo '</div>';
    echo '<div>';
    echo 'File: ' . $this->formText('file', $file, array('size' => '10', 'id' => 'file'));
    echo '&nbsp;&nbsp;&nbsp;';
    echo 'Limit:' . $this->formText('limit', $limit, array('size' => '4', 'id' => 'limit'));
    echo '</div>';
    echo "<button id='submit_index' 'type='submit' value='Index'>Start</button>";
    echo '</form>';
}
else
{
    $operation = 'none';
}

$filename = $avantElasticsearchIndexBuilder->getindexDataFilename($file);

$responses = array();
if ($operation == 'export_all' || $operation == 'export_limit')
{
    $limit = $operation == 'export_all' ? 0 : $limit;
    $responses = $avantElasticsearchIndexBuilder->performBulkIndexExport($filename, $limit);
}
else if ($operation == 'import_new' || $operation == 'import_update')
{
    $deleteExistingIndex = $operation == 'import_new';
    $responses = $avantElasticsearchIndexBuilder->performBulkIndexImport($filename, $deleteExistingIndex);
}

$message = $avantElasticsearchIndexBuilder->convertResponsesToMessageString($responses);
$executionTime = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);

if ($operation != 'none')
{
    echo '<hr/>';
    if (empty($message))
    {
        echo "<p class='health-report-ok'>SUCCESS</p>";
    }
    else
    {
        echo "<p>ERRORS</p>";
        echo "<p class='health-report-error'>$message</p>";
    }
    echo "<div>$options[$operation]</br>Execution time: $executionTime seconds</div>";
}

$mem2 = intval(memory_get_usage() / $mb);
$used = intval($mem2 - $mem1);
$peak = intval(memory_get_peak_usage() /  $mb);
echo "<hr/>";
echo '<div>';
if ($operation != 'none')
{
    echo "Memory used: $used MB</br>";
    echo "Peak usage: $peak MB</br>";
}
echo "File name: $filename";
echo '</div>';

echo foot();
?>
