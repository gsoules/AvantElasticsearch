<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialProvider;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;


class AvantElasticsearchClient extends AvantElasticsearch
{
    /* @var $client Elasticsearch\Client */
    private $client = null;
    private $lastError = '';
    private $lastException = null;

    public function __construct(array $options = array())
    {
        parent::__construct();
        $this->createElasticsearchClient($options);
    }

    public function convertElasticsearchErrorToMessage($id, $error)
    {
        $reason = isset($error['reason']) ? $error['reason'] : '';
        $causedBy = isset($error['caused_by']['reason']) ? $error['caused_by']['reason'] : '';
        $message = $id . ' : ' . $error['type'] . ' - ' . $reason . ' - ' . $causedBy;
        return $message;
    }

    protected function createElasticsearchClient(array $options)
    {
        // We don't know the appropriate timeout (seconds), but lowering it to 10 seems to have allowed the
        // "No alive nodes" exception to occur during bulk indexing operations which can take several seconds.
        // Also, the occasional calls to a slow host like googleapis.com might contribute to the problem.
        $timeout = isset($options['timeout']) ? $options['timeout'] : 90;

        $nobody = isset($options['nobody']) ? $options['nobody'] : false;

        $builder = ClientBuilder::create();

        $hosts = $this->getHosts();
        if (isset($hosts))
        {
            $builder->setHosts($hosts);
        }

        $handler = $this->getHandler();
        if (isset($handler))
        {
            $builder->setHandler($handler);
        }

        $builder->setConnectionParams([
            'client' => [
                'curl' => [CURLOPT_TIMEOUT => $timeout, CURLOPT_NOBODY => $nobody]
            ]
        ]);

        try
        {
            // Create the actual Elasticsearch Client object.
            $this->client = $builder->build();
        }
        catch (Exception $e)
        {
            $this->recordException($e);
        }
    }

    public function createIndex($params)
    {
        try
        {
            $this->client->indices()->create($params);
            return true;
        }
        catch (Exception $e)
        {
            $this->recordException($e);
            return false;
        }
    }

    public function deleteDocumentsByContributor($params, $failedAttemptOk = false)
    {
        try
        {
            if ($this->client)
            {
                $this->client->deleteByQuery($params);
                return true;
            }
            else
            {
                $this->lastError = 'Failed to remove contributor from index: Client is null';
                return false;
            }
        }
        catch (Exception $e)
        {
            $className = get_class($e);
            if ($failedAttemptOk && $className == 'Elasticsearch\Common\Exceptions\Missing404Exception')
            {
                return true;
            }
            else
            {
                $this->recordException($e);
                return false;
            }
        }
    }

    public function deleteDocument($params, $failedAttemptOk = false)
    {
        try
        {
            $this->client->delete($params);
            return true;
        }
        catch (Exception $e)
        {
            $className = get_class($e);
            if ($failedAttemptOk && $className == 'Elasticsearch\Common\Exceptions\Missing404Exception')
            {
                return true;
            }
            else
            {
                $this->recordException($e);
                return false;
            }
        }
    }

    public function deleteIndex($params)
    {
        try
        {
            if ($this->client)
            {
                $this->client->indices()->delete($params);
                return true;
            }
            else
            {
                // This should never happen, but it has during debugging so we handle it.
                $this->lastError = 'Failed to delete index: Client is null';
                return false;
            }
        }
        catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e)
        {
            // Index not found. This should never happen under normal operation, but can occur while debugging if
            // you stop execution after an index is deleted, but before it gets recreated. The next attempt to
            // delete the index will trigger this exception.
            //
            // This code handles the exception and returns true instead of first calling indices()->exists() to see
            // if a delete is needed. However, the exists() call caused a timeout with a "no alive nodes in your cluster"
            // error unless CURLOPT_NOBODY was set to true (NOBODY means don't return the body). Simply allowing the
            // exception to occur and handling it like this seems both okay and more efficient than calling exists().
            return true;
        }
        catch (Exception $e)
        {
            $this->lastException = $e;
            $this->lastError = $this->getElasticsearchExceptionMessage($e);
            $this->lastError = __('Failed to delete index: %s', $this->lastError);
            return false;
        }
    }

    protected function getHandler()
    {
        // Provide a signing handler for use with the official Elasticsearch-PHP client.
        // The handler will load AWS credentials and send requests using a RingPHP cURL handler.
        // Without this handler, a curl request to Elasticsearch on AWS will return a 403 Forbidden response.

        $key = ElasticsearchConfig::getOptionValueForKey();
        $secret = ElasticsearchConfig::getOptionValueForSecret();
        $creds = new Credentials($key, $secret);
        $region = ElasticsearchConfig::getOptionValueForRegion();
        $provider = CredentialProvider::fromCredentials($creds);

        return new ElasticsearchPhpHandler($region, $provider);
    }

    public function getHealth()
    {
        try
        {
            if ($this->client)
            {
                $response = $this->client->cat()->health();
                $health = $response[0];
                $healthReport = array('ok' => true, 'message' => "Cluster OK. Health status {$health['status']} ({$health['cluster']})");
            }
            else
            {
                $healthReport = array('ok' => false, 'message' => "Unable to communicate with the Elasticsearch server.<br/>Verify that the AvantElasticsearch plugin configuration is correct.");
            }
        }
        catch (Exception $e)
        {
            $this->recordException($e);
            $healthReport = array('ok' => false, 'message' => $this->lastError);
        }

        return $healthReport;
    }

    protected function getHosts()
    {
        // The Amazon AWS documentation says that it always uses port 443 for https Elasticsearch access.
        // As such, port and scheme are not configurable by the user.

        $host = [
            'host' => ElasticsearchConfig::getOptionValueForHost(),
            'port' => 443,
            'scheme' => 'https',
            'user' => '',
            'pass' => ''
        ];

        return [$host];
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getLastException()
    {
        return $this->lastException;
    }

    public function indexBulkDocuments($bulkDocumentsSet)
    {
        try
        {
            $response = $this->client->bulk($bulkDocumentsSet);
        }
        catch (Exception $e)
        {

            $this->recordException($e);
            return false;
        }

        if ($response['errors'] == true)
        {
            $errorCount = 0;
            $errorsReported = 0;
            $errorMessage = '';
            $maxErrorsShown = 9;

            foreach ($response['items'] as $responseItem)
            {
                if (isset($responseItem['index']['error']))
                {
                    $errorCount++;
                    $id = $responseItem['index']['_id'];
                    $error = $responseItem['index']['error'];
                    if ($errorCount <= $maxErrorsShown)
                    {
                        $errorsReported++;
                        if ($errorCount > 1)
                            $errorMessage .= '<br/>';
                        $errorMessage .= $this->convertElasticsearchErrorToMessage($id, $error);
                    }
                }
            }

            if ($errorCount > $errorsReported)
            {
                $errorMessage .= __('<br/>Showing %s of %s errors', $errorsReported, $errorCount);
            }
            $this->lastError = $errorMessage;
            return false;
        }

        return true;
    }

    public function indexDocument($params)
    {
        try
        {
            $this->client->index($params);
        }
        catch (Exception $e)
        {
            $this->recordException($e);
            return false;
        }
    }

    public function ready()
    {
        return isset($this->client);
    }

    protected function recordException(Exception $e)
    {
        $this->lastException = $e;
        $this->lastError = $this->getElasticsearchExceptionMessage($e);
        $this->reportException();
    }

    protected function reportException()
    {
        $queryArgs = urldecode(http_build_query($_GET));
        $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '<not set>';
        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '<not set>';
        $message = $this->lastException->getMessage();
        $code = $this->lastException->getCode();

        $trace = $this->lastException->getTraceAsString();
        date_default_timezone_set("America/New_York");

        $subject = 'Exception in ES client on ' . date("Y-m-d H:i:s");
        $body = $this->lastError;
        $body = str_replace('<br/>', PHP_EOL, $body);
        $body .= PHP_EOL . PHP_EOL . 'CODE:' . PHP_EOL . $code;
        $body .= PHP_EOL . PHP_EOL . 'QUERY:' . PHP_EOL . $queryArgs;
        $body .= PHP_EOL . PHP_EOL . 'HTTP_REFERER:' . PHP_EOL . $referrer;
        $body .= PHP_EOL . PHP_EOL . 'REQUEST URI:' . PHP_EOL . $requestUri;
        $body .= PHP_EOL . PHP_EOL . 'JSON:' . PHP_EOL . $message;
        $body .= PHP_EOL . PHP_EOL . 'TRACE:' . PHP_EOL . $trace;

        AvantCommon::sendEmailToAdministrator('ES Error', $subject, $body);
    }

    public function search($params)
    {
        try
        {
            $response = $this->client->search($params);
            return $response;
        }
        catch (\Elasticsearch\Common\Exceptions\NoNodesAvailableException $e)
        {
            $this->recordException($e);
            return null;
        }
        catch (Exception $e)
        {
            $this->recordException($e);
            if (strpos($this->rootCauseReason, 'No mapping found') === 0)
            {
                // This should only happen if someone manually edited the sort argument in the query string.
                $column = array_keys($params["body"]["sort"][0])[0];
                $this->lastError = __("Invalid sort column: '%s'", $column);
            }
            return null;
        }
    }

    public function suggest($params)
    {
        try
        {
            $response = $this->search($params);
            $rawSuggestions = isset($response["suggest"]["keywords-suggest"][0]["options"]) ? $response["suggest"]["keywords-suggest"][0]["options"] : array();
            return $rawSuggestions;
        }
        catch (Exception $e)
        {
            $this->recordException($e);
            return null;
        }
    }
}