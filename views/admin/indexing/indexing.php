<?php

$avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

if (AvantCommon::isAjaxRequest())
{
    // This page just got called to handle an asynchronous Ajax request. Execute the request synchronously,
    // waiting here until it completes (when handleAjaxRequest returns). When ths page returns,  the request's
    // success function will execute in the browser (or its error function if something went wrong).
    // Give the request plenty of time to execute since it can take several minutes.
    ini_set('max_execution_time', 10 * 60);
    $avantElasticsearchIndexBuilder->handleAjaxRequest();
    return;
}

$pageTitle = __('Elasticsearch Indexing');
echo head(array('title' => $pageTitle, 'bodyclass' => 'indexing'));

// Initialize the action options.
$options = array(
    'none' => 'No Action',
    'export_all' => 'Export all items from Omeka',
    'export_some' => 'Export 100 items from Omeka',
    'import_existing' =>'Import into existing index',
    'import_new' => 'Import into new index'
    );

// Warn if this session is running in the debugger because simultaneous Ajax requests won't work while debugging.
if (isset($_COOKIE['XDEBUG_SESSION']))
{
    echo '<div class="health-report-error">XDEBUG_SESSION in progress. Simultaneous Ajax requests will not work properly.<br/>';
    echo '<a href="http://localhost/omeka-2.6/?XDEBUG_SESSION_STOP" target="_blank">Click here to stop debugging</a>';
    echo '</div>';
}

// Display the cluster health.
$avantElasticsearchClient = new AvantElasticsearchClient();
$health = $avantElasticsearchClient->getHealth();
$healthReport = $health['message'];
$healthReportClass = ' class="health-report-' . ($health['ok'] ? 'ok' : 'error') . '"';
echo "<div$healthReportClass>$healthReport</div>";

// Display whether the server supports PDF searching.
$avantElasticsearchDocument = new AvantElasticsearchDocument(null);
$pdfToTextIsSupported = $avantElasticsearchDocument->pdfSearchingIsSupported();
$pdfReportClass = ' class="health-report-' . ($pdfToTextIsSupported ? 'ok' : 'error') . '"';
$pdfSupportReport = $pdfToTextIsSupported ? 'PDF searching is enabled' : 'PDF searching is not supported on this server because pdftotext is not installed.';
echo "<div$pdfReportClass>$pdfSupportReport</div>";

// Display the action radio buttons and the Start button.
if ($avantElasticsearchClient->ready())
{
    $filePrefix =  date('md') . '-' . ElasticsearchConfig::getOptionValueForContributorId();
    echo "<hr/>";
    echo '<div class="indexing-radio-buttons">' . $this->formRadio('action', 'none', null, $options) . '</div>';
    echo '<div>File: ' . $this->formText('file', $filePrefix, array('size' => '12', 'id' => 'file')). '</div>';
    echo "<button id='start-button'>Start</button>";
    echo '<div id="status-area"></div>';
}
echo foot();

// Form the URL for this page which is the same page that satisfies the Ajax requests.
$url = WEB_ROOT . '/admin/elasticsearch/indexing';
?>

<script type="text/javascript">
    jQuery(document).ready(function ()
    {
        var action = jQuery("input[name='action']");
        var actionCompleted = false;
        var fileName = jQuery("#file").val();
        var progressCount = 0;
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

            // Set up the handlers that respond to radio button and Start button clicks.
            action.change(function (e)
            {
                // The admin has selected a different radio button.
                var checkedButton = jQuery("input[name='action']:checked");
                selectedAction = checkedButton.val();
                enableStartButton(selectedAction !== 'none');
            });

            startButton.on("click", function()
            {
                if (selectedAction === 'import_new')
                {
                    if (!confirm('Are you sure you want to create a new index?\n\nThe current index will be DELETED.'))
                        return;
                }
                startIndexing();
            });
        }

        function reportProgress()
        {
            if (actionCompleted)
                return;

            console.log('reportProgress ' + ++progressCount);

            // Call back to the server (this page) to get the status of the indexing action.
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
                            progressTimer = setTimeout(reportProgress, 2000);
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
            actionCompleted = false;

            // Initiate periodic calls back to the server to get the status of the indexing action.
            progressCount = 0;
            progressTimer = setTimeout(reportProgress, 1000);

            // Call back to the server (this page) to initiate the indexing action which can take several minutes.
            // While waiting, the reportProgress function is called on a timer to get the status of the action.
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

