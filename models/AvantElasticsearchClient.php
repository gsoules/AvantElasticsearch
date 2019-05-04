<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialProvider;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;


class AvantElasticsearchClient extends AvantElasticsearch
{
    /* @var $client Elasticsearch\Client */
    private $client;
    private $error;

    public function __construct(array $options = array())
    {
        parent::__construct();
        $this->createElasticsearchClient($options);
    }

    public function convertElasticsearchErrorToMessage($response)
    {
        $messageString = '';

        if (isset($response['error']))
        {
            $error = $response['error'];
            $reason = isset($error['reason']) ? $error['reason'] : '';
            $causedBy = isset($error['caused_by']['reason']) ? $error['caused_by']['reason'] : '';
            $message = $response['_id'] . ' : ' . $error['type'] . ' - ' . $reason . ' - ' . $causedBy;
            $messageString .= $message;
        }
        else
        {
            $messageString = __('NO ERROR MESSAGE');
        }

        return $messageString;
    }

    protected function createElasticsearchClient(array $options)
    {
        $timeout = isset($options['timeout']) ? $options['timeout'] : 30;
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
            $this->reportClientException($e);
            return null;
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
            $this->reportClientException($e);
            return false;
        }
    }

    public function deleteDocument($params)
    {
        try
        {
            $this->client->delete($params);
            return true;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return false;
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
                $this->error = 'Failed to delete index: Client is null';
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
            $this->error = $this->getElasticsearchExceptionMessage($e);
            $this->error = __('Failed to delete index: %s', $this->error);
            return false;
        }
    }

    public function getError()
    {
        return $this->error;
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
                $healthReport = array('ok' => false, 'message' => "The Elasticsearch plugin has not been configured.");
            }
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            $healthReport = array('ok' => false, 'message' => $this->error);
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

    public function indexBulkDocuments($bulkDocumentsSet)
    {
        try
        {
            $response = $this->client->bulk($bulkDocumentsSet);
        }
        catch (Exception $e)
        {

            $this->reportClientException($e);
            return false;
        }

        if ($response['errors'] == true)
        {
            // Get the error for the first item. No point in reporting all of them since they are probably the same
            // and even if they are different, this one needs to get fixed and after that, any other errors will surface.
            $this->error = $this->convertElasticsearchErrorToMessage($response['items'][0]['index']);
            return false;
        }

        return true;
    }

    public function indexDocument($params)
    {
        try
        {
            $response = $this->client->index($params);
            return $response;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
        }
    }

    public function ready()
    {
        return $this->client != null;
    }

    protected function reportClientException(Exception $e)
    {
        // TO-DO: Need to figure out what to do in this situation. For now keep a breakpoint here.
        $this->error = $this->getElasticsearchExceptionMessage($e);
        return;
    }

    public function search($params, $attempt = 1)
    {
        try
        {
            $response = $this->client->search($params);
            return $response;
        }
        catch (\Elasticsearch\Common\Exceptions\NoNodesAvailableException $e)
        {
            if ($attempt == 3)
            {
                $error = $this->getElasticsearchExceptionMessage($e);
                $this->error = $error . '<br/>' . "Tried $attempt times";
                return null;
            }
            else
            {
                $attempt++;
                $response = $this->search($params, $attempt);
                return $response;
            }
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
        }
    }

    public function suggest($params)
    {
        try
        {
            $response = $this->search($params);
            $options = isset($response["suggest"]["keywords-suggest"][0]["options"]) ? $response["suggest"]["keywords-suggest"][0]["options"] : array();
            return $options;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
        }
    }
}