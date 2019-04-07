<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialProvider;
use Aws\ElasticsearchService\ElasticsearchPhpHandler;

class AvantElasticsearchClient extends AvantElasticsearch
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function create(array $options = array())
    {
        $timeout = isset($options['timeout']) ? $options['timeout'] : 90;
        $nobody = isset($options['nobody']) ? $options['nobody'] : false;

        $builder = ClientBuilder::create();

        $hosts = self::getHosts();
        if (isset($hosts))
        {
            $builder->setHosts($hosts);
        }

        $handler = self::getHandler();
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
        $client = $builder->build();
        return $client;
    }

    protected static function getHandler()
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

    protected static function getHosts()
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
}