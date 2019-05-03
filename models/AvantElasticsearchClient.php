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
            $response = $this->client->indices()->create($params);
            return $response;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
        }
    }

    public function deleteDocument($params)
    {
        try
        {
            $response = $this->client->delete($params);
            return $response;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
        }
    }

    public function deleteIndex($params)
    {
        try
        {
            if ($this->client)
            {
                $response = $this->client->indices()->delete($params);
                return $response;
            }
            else
            {
                // TO-DO: Return an error if deleteIndex fails because of no client
                return null;
            }
        }
        catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e)
        {
            // Index not found.
            // This should never happen under normal operation, but can occur while debugging if execution gets
            // stopped after an index is deleted, but before it gets recreated. The next attempt to delete the
            // index will trigger this exception. We are handling the exception instead of first testing with
            // indices()->exists() since the exists call caused a timeout with a "no alive nodes in your cluster"
            // error unless CURLOPT_NOBODY was set to true.  Note that 'nobody' means don't return the body.
            return null;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            return null;
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

    public function indexMultipleDocuments($params)
    {
        try
        {
            $response = $this->client->bulk($params);
            return $response;
        }
        catch (Exception $e)
        {
            $this->reportClientException($e);
            $response['errors'] = false;
            $response['exception'] = $this->error;
            return $response;
        }
    }

    public function ready()
    {
        return $this->client != null;
    }

    protected function reportClientException(Exception $e)
    {
        // FINISH: Need to figure out what to do in this situation. For now keep a breakpoint here.
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