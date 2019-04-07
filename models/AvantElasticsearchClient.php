<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialProvider;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;


class AvantElasticsearchClient extends AvantElasticsearch
{
    private $client;

    public function __construct(array $options = array())
    {
        parent::__construct();
        $this->createElasticsearchClient($options);
    }

    protected function createElasticsearchClient(array $options)
    {
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

        // Return the Elasticsearch\Client object;
        $this->client = $builder->build();
    }

    public function createIndex($params)
    {
        $response = $this->client->indices()->create($params);
        return $response;
    }

    public function deleteIndex($params)
    {
        try
        {
            $response = null;

            if ($this->client->indices()->exists($params))
            {
                $response = $this->client->indices()->delete($params);
            }

            return $response;
        }
        catch (Exception $e)
        {
            return null;
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

    protected function getHosts()
    {
        $host = [
            'host' => ElasticsearchConfig::getOptionValueForHost(),
            'port' => ElasticsearchConfig::getOptionValueForPort(),
            'scheme' => ElasticsearchConfig::getOptionValueForScheme(),
            'user' => '',
            'pass' => ''
        ];

        return [$host];
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
            return null;
        }
    }

    public function indexDocument($params)
    {
        $response = $this->client->index($params);
        return $response;
    }

    public function indexMultipleDocuments($params)
    {
        $response = $this->client->bulk($params);
        return $response;
    }

    public function performQuery($params)
    {
        $response = $this->client->search($params);
        return $response;
    }
}