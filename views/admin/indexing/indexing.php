<?php
ini_set('max_execution_time', 1200);

$status['events'] = array();

$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

$fileDefault =  date('md') . '-' . ElasticsearchConfig::getOptionValueForContributorId();
$fileName = isset($_REQUEST['file']) ? $_REQUEST['file'] : $fileDefault;
$filePath = $avantElasticsearchIndexBuilder->getIndexDataFilename($fileName);

$limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100;

$bulkExportRequest = isset($_REQUEST['bulk-export']);
if ($bulkExportRequest)
{
    $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
    $status = $avantElasticsearchIndexBuilder->performBulkIndexExport($filePath, $limit);
    $status = json_encode($status);
    echo $status;
    return;
}

$progressRequest = isset($_REQUEST['progress']);
if ($progressRequest)
{
    $percent = isset($_SESSION['progress'] ) ? $_SESSION['progress']  : 50;
    $percent = json_encode(array('percent' => $percent));
    echo $percent;
    return;
}

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
    //echo "<form id='indexing-form' name='indexing-form' action='$action' method='get'>";
    echo '<div class="indexing-radio-buttons">';
    echo $this->formRadio('operation', 'none', null, $options);
    echo '</div>';
    echo '<div id="limit-section">Limit: ' . $this->formText('limit', $limit, array('size' => '4', 'id' => 'limit')) . '</div>';
    echo '<div>File: ' . $this->formText('file', $fileName, array('size' => '12', 'id' => 'file')). '</div>';
    echo "<button id='start-button' 'xtype='submit' value='Index'>Start</button>";
    //echo '</form>';
}
else
{
    $operation = 'none';
}
?>
<div id="dialog" title="File Download">
    <div class="progress-label">Starting download...</div>
    <div id="progressbar"></div>
</div>
<!--<button id="downloadButton">Start Download</button>-->
<?php

$eventsMessages = '';

if ($operation == 'export_all' || $operation == 'export_limit')
{
    $limit = $operation == 'export_all' ? 0 : $limit;
    //$status = $avantElasticsearchIndexBuilder->performBulkIndexExport($filePath, $limit);
}
else if ($operation == 'import_new' || $operation == 'import_update')
{
    $deleteExistingIndex = $operation == 'import_new';
    //$status = $avantElasticsearchIndexBuilder->performBulkIndexImport($filePath, $deleteExistingIndex);
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
echo "File name: $filePath";
echo '</div>';

echo foot();
?>
<style>
    #progressbar {
        margin-top: 20px;
    }

    .progress-label {
        font-weight: bold;
        text-shadow: 1px 1px 0 #fff;
    }

    .ui-dialog-titlebar-close {
        display: none;
    }
</style>
<script type="text/javascript">
    jQuery(document).ready(function () {
        var limitSection = jQuery("#limit-section");
        var startButton = jQuery("#start-button");
        setControls(true, true);

        function setControls(disableStartButton, hideLimit) {
         //   startButton.prop("disabled", disableStartButton);
            hideLimit ? limitSection.hide() : limitSection.show();
        }

        jQuery("input[name='operation']").change(function (e) {
            var checkedButton = jQuery("input[name='operation']:checked");
            var value = checkedButton.val();
            var disableStartButton = value === 'none';
            setControls(disableStartButton, value !== 'export_limit');
        });

        jQuery(function () {
            var progressTimer,
                progressbar = jQuery("#progressbar"),
                progressLabel = jQuery(".progress-label"),
                dialogButtons = [{
                    text: "Cancel Download",
                    click: closeDownload
                }],
                dialog = jQuery("#dialog").dialog({
                    autoOpen: false,
                    closeOnEscape: false,
                    resizable: false,
                    buttons: dialogButtons,
                    open: function () {
                        progressTimer = setTimeout(progress, 2000);
                    },
                    beforeClose: function () {
                        downloadButton.button("option", {
                            disabled: false,
                            label: "Start Download"
                        });
                    }
                }),
                downloadButton = jQuery("#start-button")
                    .button()
                    .on("click", function () {
                        // jQuery(this).button("option", {
                        //     disabled: true,
                        //     label: "Downloading..."
                        // });
                        // dialog.dialog("open");
                        jQuery.ajax(
                            "http://localhost/omeka-2.6/admin/elasticsearch/indexing?bulk-export=true",
                            {
                                method: 'POST',
                                dataType: 'json',
                                success: function (data)
                                {
                                    alert(data['events'][0]);
                                },
                                error: function (request, status, error)
                                {
                                    alert('BULK EXPORT ERROR: ' + status + error + request.responseText);
                                }
                            }
                        );
                    });

            progressbar.progressbar({
                value: false,
                change: function () {
                    progressLabel.text("Current Progress: " + progressbar.progressbar("value") + "%");
                },
                complete: function () {
                    progressLabel.text("Complete!");
                    dialog.dialog("option", "buttons", [{
                        text: "Close",
                        click: closeDownload
                    }]);
                    jQuery(".ui-dialog button").last().trigger("focus");
                }
            });

            function progress()
            {
                console.log('Called progress');
                jQuery.ajax(
                    "http://localhost/omeka-2.6/admin/relationships/browse?progress=true",
                    {
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'add'
                        },
                        success: function (data)
                        {
                            var percent = data.percent;
                            console.log('Percent = ' + percent);
                            //alert('RESPONSE: ' + percent);
                            if (percent > 0) {
                                progressbar.progressbar("value", percent);
                            }
                            if (percent <= 99) {
                                progressTimer = setTimeout(progress, 1000);
                            }
                        },
                        error: function (request, status, error)
                        {
                            alert('ERROR: ' + status + error + request.responseText);
                            console.log('***' + status + error + request.responseText);
                        }
                    }
                );
            }

            function closeDownload() {
                clearTimeout(progressTimer);
                dialog
                    .dialog("option", "buttons", dialogButtons)
                    .dialog("close");
                progressbar.progressbar("value", false);
                progressLabel
                    .text("Starting download...");
                downloadButton.trigger("focus");
            }
        });
    });
</script>

