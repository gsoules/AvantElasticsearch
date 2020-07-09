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

$contributorId = ElasticsearchConfig::getOptionValueForContributorId();
$sharedIndexName = AvantElasticsearch::getNameOfSharedIndex();

// Initialize the action options.
$options = array(
    'export-all' => ' Export all items from Omeka',
    'import-local-existing' => " Import into existing local index ($contributorId)",
    'remove-shared' => " Remove all items from shared index ($sharedIndexName)",
    'import-shared-existing' => " Import into existing shared index ($sharedIndexName)"
    );

if (AvantElasticsearch::getNewLocalIndexAllowed())
{
    $options['export-some'] = " *Export 100 items from Omeka";
    $options['import-local-new'] = " *Import into new local index ($contributorId)";
}

if (AvantElasticsearch::getNewSharedIndexAllowed())
{
    $options['import-shared-new'] = " *Import into new shared index ($sharedIndexName)";
}

// Warn if this session is running in the debugger because simultaneous Ajax requests won't work while debugging.
if (isset($_COOKIE['XDEBUG_SESSION']))
{
    echo '<div class="health-report-error">XDEBUG_SESSION in progress. Indexing status will not be reported in real-time.<br/>';
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
$pdfToTextIsSupported = AvantElasticsearchDocument::pdfSearchingIsSupported();
$pdfReportClass = ' class="health-report-' . ($pdfToTextIsSupported ? 'ok' : 'error') . '"';
$pdfSupportReport = $pdfToTextIsSupported ? 'PDF searching is enabled' : 'PDF searching is not supported on this server because pdftotext is not installed.';
echo "<div$pdfReportClass>$pdfSupportReport</div>";

// Warn if the elasticsearch files directory does not exist.
$esDirectoryName = $avantElasticsearchIndexBuilder->getElasticsearchFilesDirectoryName();
$esDirectoryExists = file_exists($esDirectoryName);
if (!$esDirectoryExists)
{
    echo '<div class="health-report-error">' . __("Elasticsearch folder '%s' is missing.", $esDirectoryName) . '</div>';
}

// Display the action radio buttons and the Start button.
if ($esDirectoryExists && $avantElasticsearchClient->ready())
{
    $indexingId =  date('md') . '-' . $contributorId;
    $indexName =  $contributorId;
    echo "<hr/>";
    echo '<div class="indexing-radio-buttons">' . $this->formRadio('action', null, null, $options) . '</div>';
    echo '<div><span style="display:inline-block;width:80px;">Indexing ID: </span><span>' . $this->formText(null, $indexingId, array('size' => '12', 'id' => 'indexing-id')) . '</span></div>';
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
        var actionButtons = jQuery("input[name='action']");
        var startButton = jQuery("#start-button").button();
        var statusArea = jQuery("#status-area");

        var actionInProgress = false;
        var indexingId = '';
        var indexingName = '';
        var indexingOperation = '';
        var progressCount = 0;
        var progressTimer;
        var selectedAction = '';
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
            actionButtons.change(function (e)
            {
                // The admin has selected a different radio button.
                var checkedButton = jQuery("input[name='action']:checked");
                selectedAction = checkedButton.val();

                if (selectedAction.startsWith('export'))
                {
                    indexingOperation = 'export';
                }
                else
                {
                    indexingOperation = 'import';
                }
                enableStartButton(true);
            });

            startButton.on("click", function()
            {
                if (selectedAction === 'import-local-new' || selectedAction === 'import-shared-new')
                {
                    if (!confirm('Are you sure you want to create a new index?\n\nThe current index will be DELETED.'))
                        return;
                }
                startIndexing();
            });
        }

        function reportAjaxError(request, action)
        {
            // Strip away HMTL tags.
            let message = JSON.stringify(request);
            message = message.replace(/(<([^>]+)>)/ig,"");
            message = message.replace(/\\n/g, '\n');
            alert('AJAX ERROR on ' + action + ' >>> ' + message);
        }

        function reportProgress()
        {
            if (!actionInProgress)
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
                        indexing_id: indexingId,
                        operation: indexingOperation
                    },
                    success: function (data)
                    {
                        showStatus(data);
                        if (actionInProgress)
                        {
                            progressTimer = setTimeout(reportProgress, 2000);
                        }
                    },
                    error: function (request, status, error)
                    {
                        // Remove the HTML tags from the message and separate the lines with actual newline characters.
                        reportAjaxError(request, 'reportProgress');
                    }
                }
            );
        }

        function showStatus(status)
        {
            status = status.replace(/(\r\n|\n|\r)/gm, '<BR/>');
            statusArea.html(status);
        }

        function startIndexing()
        {
            actionInProgress = true;
            statusArea.html('');
            indexingId = jQuery("#indexing-id").val();

            enableStartButton(false);

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
                        indexing_id: indexingId,
                        operation: indexingOperation
                    },
                    success: function (data)
                    {
                        actionInProgress = false;
                        showStatus(data);
                        enableStartButton(true);
                    },
                    error: function (request, status, error)
                    {
                        clearTimeout(progressTimer);
                        reportAjaxError(request, selectedAction);
                    }
                }
            );
        }
    });
</script>

