<?php
ini_set('max_execution_time', 1200);

$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
$avantElasticsearchClient = new AvantElasticsearchClient();
$avantElasticsearchDocument = new AvantElasticsearchDocument(null);

$health = $avantElasticsearchClient->getHealth();
$healthReport = $health['message'];
$healthReportClass = ' class="health-report-' . ($health['ok'] ? 'ok' : 'error') . '"';

$pdfToTextIsSupported = $avantElasticsearchDocument->pdfSearchingIsSupported();
$pdfReportClass = ' class="health-report-' . ($pdfToTextIsSupported ? 'ok' : 'error') . '"';
$pdfSupportReport = $pdfToTextIsSupported ? 'PDF searching is enabled' : 'PDF searching is not supported on this server because pdftotext is not installed.';

$pageTitle = __('Elasticsearch Indexing');
$operation = isset($_REQUEST['operation']) ? $_REQUEST['operation'] : 'none';
$limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100;
$fileDefault =  date('md') . '-' . ElasticsearchConfig::getOptionValueForContributorId();
$file = isset($_REQUEST['file']) ? $_REQUEST['file'] : $fileDefault;


if (isset($_REQUEST['new']) && $_REQUEST['new'] == 'true')
{
    // This is a dangerous operation, so make sure the admin really wants to destroy the current index.
    $options['import_new'] = 'Import into new index';
}
else
{
    $options = array(
        'none' => 'No Action',
        'export_all' => 'Export all items from Omeka',
        'export_limit' => 'Export limited items from Omeka',
        'import_update' =>'Import into existing index (add &new=true to the query string to create a new index)'
    );
}

$action = url("elasticsearch/indexing?operation=$operation&limit=$limit");
$mem1 = memory_get_usage() / MB_BYTES;
$errorMessage = '';

echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));
echo "<div$healthReportClass>$healthReport</div>";
echo "<div$pdfReportClass>$pdfSupportReport</div>";

if ($avantElasticsearchClient->ready())
{
    echo "<hr/>";
    echo "<form id='indexing-form' name='indexing-form' action='$action' method='get'>";
    echo '<div class="indexing-radio-buttons">';
    echo $this->formRadio('operation', 'none', null, $options);
    echo '</div>';
    echo '<div id="limit-section">Limit: ' . $this->formText('limit', $limit, array('size' => '4', 'id' => 'limit')) . '</div>';
    echo '<div>File: ' . $this->formText('file', $file, array('size' => '12', 'id' => 'file')). '</div>';
    echo "<button id='submit_index' 'type='submit' value='Index'>Start</button>";
    echo '</form>';
}
else
{
    $operation = 'none';
}

$filename = $avantElasticsearchIndexBuilder->getIndexDataFilename($file);

$status['events'] = array();
$eventsMessages = '';

if ($operation == 'export_all' || $operation == 'export_limit')
{
    $limit = $operation == 'export_all' ? 0 : $limit;
    $status = $avantElasticsearchIndexBuilder->performBulkIndexExport($filename, $limit);
}
else if ($operation == 'import_new' || $operation == 'import_update')
{
    $deleteExistingIndex = $operation == 'import_new';
    $status = $avantElasticsearchIndexBuilder->performBulkIndexImport($filename, $deleteExistingIndex);
}

if ($operation != 'none')
{
    $hasError = isset($status['error']);
    $errorMessage = $hasError ? $status['error'] : '';
    foreach ($status['events'] as $eventMessage)
    {
        $eventsMessages .= $eventMessage . '<br/>';
    }
}

$executionTime = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);

if ($operation != 'none')
{
    echo "<div class='health-report-ok'>$eventsMessages</div>";
    if (empty($errorMessage))
    {
        echo '<hr/>';
        echo "<div class='health-report-ok'>SUCCESS</div>";
    }
    else
    {
        echo "<div class='health-report-error'>$errorMessage</div>";
    }
    echo '<hr/>';
    echo "<div>$options[$operation]</br>Execution time: $executionTime seconds</div>";
}

$mem2 = memory_get_usage() / MB_BYTES;
$used = $mem2 - $mem1;
$peak = memory_get_peak_usage() /  MB_BYTES;
echo "<hr/>";
echo '<div>';
if ($operation != 'none')
{
    echo 'Memory used: ' . number_format($used, 2) . ' MB</br>';
    echo 'Peak usage: ' . number_format($peak, 2) . ' MB</br>';
}
echo "File name: $filename";
echo '</div>';

echo foot();
?>

<script type="text/javascript">
    jQuery(document).ready(function ()
    {
        var limitSection = jQuery("#limit-section");
        var startButton = jQuery("#submit_index");
        setControls(true, true);

        function setControls(disableStartButton, hideLimit)
        {
            startButton.prop("disabled", disableStartButton);
            hideLimit ? limitSection.hide() : limitSection.show();
        }

        jQuery("input[name='operation']").change(function (e)
        {
            var checkedButton = jQuery("input[name='operation']:checked");
            var value = checkedButton.val();
            var disableStartButton = value === 'none';
            setControls(disableStartButton, value !== 'export_limit');
        });
    });
</script>

