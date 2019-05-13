<?php

$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
$fileDefault =  date('md') . '-' . ElasticsearchConfig::getOptionValueForContributorId();
$fileName = isset($_REQUEST['file']) ? $_REQUEST['file'] : $fileDefault;
$filePath = $avantElasticsearchIndexBuilder->getIndexDataFilename($fileName);
$url = WEB_ROOT . '/admin/elasticsearch/indexing';


if (AvantCommon::isAjaxRequest())
{
    ini_set('max_execution_time', 1200);
    $avantElasticsearchIndexBuilder->handleAjaxRequest();
    return;
}

$status['events'] = array();

$avantElasticsearchClient = new AvantElasticsearchClient();
$avantElasticsearchDocument = new AvantElasticsearchDocument(null);

$health = $avantElasticsearchClient->getHealth();
$healthReport = $health['message'];
$healthReportClass = ' class="health-report-' . ($health['ok'] ? 'ok' : 'error') . '"';

$pdfToTextIsSupported = $avantElasticsearchDocument->pdfSearchingIsSupported();
$pdfReportClass = ' class="health-report-' . ($pdfToTextIsSupported ? 'ok' : 'error') . '"';
$pdfSupportReport = $pdfToTextIsSupported ? 'PDF searching is enabled' : 'PDF searching is not supported on this server because pdftotext is not installed.';

$pageTitle = __('Elasticsearch Indexing');
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'none';

if (isset($_REQUEST['new']) && $_REQUEST['new'] == 'true')
{
    // This is a dangerous action, so make sure the admin really wants to destroy the current index.
    $options['import_new'] = 'Import into new index';
}
else
{
    $options = array(
        'none' => 'No Action',
        'export_all' => 'Export all items from Omeka',
        'export_some' => 'Export 100 items from Omeka',
        'import_update' =>'Import into existing index (add &new=true to the query string to create a new index)'
    );
}

$mem1 = memory_get_usage() / MB_BYTES;
$errorMessage = '';

echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

if (isset($_COOKIE['XDEBUG_SESSION']))
{
    echo '<div class="health-report-error">YOU ARE DEBUGGING</div>';
}
echo "<div$healthReportClass>$healthReport</div>";
echo "<div$pdfReportClass>$pdfSupportReport</div>";

if ($avantElasticsearchClient->ready())
{
    echo "<hr/>";
    echo '<div class="indexing-radio-buttons">';
    echo $this->formRadio('action', 'none', null, $options);
    echo '</div>';
    echo '<div>File: ' . $this->formText('file', $fileName, array('size' => '12', 'id' => 'file')). '</div>';
    echo "<button id='start-button'>Start</button>";
    echo '<div id="status-area"></div>';
}
else
{
    $action = 'none';
}

$eventsMessages = '';

if ($action != 'none')
{
    $hasError = isset($status['error']);
    $errorMessage = $hasError ? $status['error'] : '';
    foreach ($status['events'] as $eventMessage)
    {
        $eventsMessages .= $eventMessage . '<br/>';
    }
}

$executionTime = intval(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]);

if ($action != 'none')
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
    echo "<div>$options[$action]</br>Execution time: $executionTime seconds</div>";
}

$mem2 = memory_get_usage() / MB_BYTES;
$used = $mem2 - $mem1;
$peak = memory_get_peak_usage() /  MB_BYTES;
echo "<hr/>";
echo '<div>';
if ($action != 'none')
{
    echo 'Memory used: ' . number_format($used, 2) . ' MB</br>';
    echo 'Peak usage: ' . number_format($peak, 2) . ' MB</br>';
}
echo "File name: $filePath";
echo '</div>';

echo foot();
?>
<script type="text/javascript">
    jQuery(document).ready(function ()
    {
        var action = jQuery("input[name='action']");
        var actionCompleted = false;
        var fileName = jQuery("#file").val();
        var progressTimer;
        var selectedAction = 'none';
        var startButton = jQuery("#start-button").button();
        var statusArea = jQuery("#status-area");
        var url = '<?php echo $url; ?>';

        initialize();

        function enableStartButton(enable)
        {
            startButton.button("option", {disabled: !enable});
        }

        function initialize()
        {
            enableStartButton(false);

            action.change(function (e)
            {
                // The admin has selected a different radio button.
                var checkedButton = jQuery("input[name='action']:checked");
                selectedAction = checkedButton.val();
                enableStartButton(selectedAction !== 'none');
            });

            startButton.on("click", function ()
            {
                startIndexing();
            });
        }

        function reportProgress()
        {
            // Call back to the server to get the status of the indexing action.
            // The server returns the complete status since the action began, not just what has since transpired.
            jQuery.ajax(
                url,
                {
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'progress',
                        file_name: fileName
                    },
                    success: function (data)
                    {
                        showStatus(data);
                        if (!actionCompleted)
                        {
                            progressTimer = setTimeout(reportProgress, 3000);
                        }
                    },
                    error: function (request, status, error)
                    {
                        alert('AJAX ERROR on reportProgress' + ' >>> ' + error);
                    }
                }
            );
        }

        function showStatus(status)
        {
            statusArea.html(status);
        }

        function startIndexing()
        {
            enableStartButton(false);
            statusArea.html('');
            progressTimer = setTimeout(reportProgress, 1000);

            // Call back to the server to initiate the indexing action. This can take several minutes to complete.
            // In the meantime, the reportProgress function is called periodically to get the status of the action.
            jQuery.ajax(
                url,
                {
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: selectedAction,
                        file_name: fileName
                    },
                    success: function (data)
                    {
                        actionCompleted = true;
                        showStatus(data);
                        enableStartButton(true);
                    },
                    error: function (request, status, error)
                    {
                        alert('AJAX ERROR on ' + selectedAction + ' >>> ' + error);
                    }
                }
            );
        }
    });
</script>

