<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

define('MB_BYTES', 1024 * 1024);

class AvantElasticsearch
{
    protected $indexOnlyPublicElements = true;
    protected $searchAll = null;

    // Should only be accessed via a getter or setter.
    private $indexName;

    // Used for caching and therefore should not be accessed directly by subclasses.
    private $elementsForIndex = array();

    public function __construct()
    {
        // Set the index name to an empty string rather than null. This way, an attempt to query Elasticsearch with no
        // index will cause an exception. If the index is null, Elasticsearch apparently queries all of the indexes.
        $this->indexName = '';
    }

    public function convertElementNameToElasticsearchFieldName($elementName)
    {
        // Convert the element name to lowercase and strip away spaces and other non-alphanumberic characters
        // as required by Elasticsearch syntax.
        return strtolower(preg_replace('/[^a-zA-Z0-9-_]/', '', $elementName));
    }

    protected function createFieldText($text, $html = 0)
    {
        return array('text' => $text, 'html' => $html);
    }

    protected function createFieldTexts($text)
    {
        return array($this->createFieldText($text));
    }

    public function getDocumentMappingType()
    {
        return '_doc';
    }

    public function getDocumentIdForItem($identifier)
    {
        // Create an id that is unique among all organizations that have items in the index.
        // The item Id alone is not sufficient since multiple organizations may have an item with
        // that Id. However, the item Id combined with the item's owner Id is unique.

        $contributorId = ElasticsearchConfig::getOptionValueForContributorId();
        $documentId = "$contributorId-$identifier";
        return $documentId;
    }

    public function getElasticsearchExceptionMessage(Exception $e)
    {
        $message = $e->getMessage();

        if ($this->isJson($message))
        {
            $jsonMessage = json_decode($message);
            if (is_object($jsonMessage))
            {
                if (isset($jsonMessage->error))
                {
                    $error = $jsonMessage->error;
                    $rootCause = $error->root_cause;
                    $rootCauseReason = $rootCause[0]->reason;
                    $errorReason = $error->reason;
                    $message = "Type: $error->type<br/>Reason: $errorReason<br/>Root cause: $rootCauseReason";
                }
                else if (isset($jsonMessage->message))
                {
                    $message = $jsonMessage->message;
                }
                else if (isset($jsonMessage->Message))
                {
                    $message = $jsonMessage->Message;
                }
                else if (isset($jsonMessage->result))
                {
                    $message = $jsonMessage->result;
                }
                else
                {
                    // Don't know what kind of object this is so just return the raw message.
                    $message = $e->getMessage();
                }
            }
        }
        return get_class($e) . '<br/>' . $message;
    }

    public function getElementsUsedByThisInstallation($public = true)
    {
        // Determine if the elements are already cached. Note that they might be in cache, but don't
        // match the public option,  in which case, they need to be fetched again per the option.
        $getElementsFromDatabase = empty($this->elementsForIndex) || $this->indexOnlyPublicElements != $public;

        if ($getElementsFromDatabase)
        {
            $this->indexOnlyPublicElements = $public;

            $db = get_db();
            $table = "{$db->prefix}elements";

            $sql = "
            SELECT $table.id, $table.name
            FROM $table";

            $elements = $db->query($sql)->fetchAll();

            // Get a list of the private elements, but exclude Identifier which might be private if using an Alias.
            $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
            $identifierElementId = ItemMetadata::getIdentifierElementId();
            unset($privateElementsData[$identifierElementId]);

            $unusedElementsData = CommonConfig::getOptionDataForUnusedElements();

            $this->elementsForIndex = array();

            foreach ($elements as $element)
            {
                $elementId = $element['id'];

                $ignoreUnused = array_key_exists($elementId, $unusedElementsData);
                $ignore = $ignoreUnused || ($this->indexOnlyPublicElements && array_key_exists($elementId, $privateElementsData));
                if ($ignore)
                {
                    continue;
                }

                $this->elementsForIndex[$elementId] = $this->convertElementNameToElasticsearchFieldName($element['name']);
            }
        }

        return $this->elementsForIndex;
    }

    public function getIndexName()
    {
        // Return the name of the index that is currently set for this object.
        return $this->indexName;
    }

    public function getIndexNameForContributor()
    {
        $contributorId = ElasticsearchConfig::getOptionValueForContributorId();
        return $contributorId;
    }

    public function getIndexNameForSharing()
    {
        $configFile = AVANTELASTICSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'config.ini';
        $configuration = new Zend_Config_Ini($configFile, 'config');
        $sharedIndexName = $configuration->shared_index_name;
        return $sharedIndexName;
    }

    public function getSearchAll()
    {
        if (!isset($this->searchAll))
        {
            // Sharing turned off means only search the contributor index.
            $searchAllNever = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == false;

            // No contributor index means only search the shared index.
            $searchAllAlways = empty($this->getIndexNameForContributor());

            // all=on in the query string means search the shared index unless sharing is turned off.
            $searchAllArg = isset($_REQUEST['all']) ? $_REQUEST['all'] == 'on' : false;

            // The search-all cookie applies when there's nothing on the query string. This will apply when someone
            // comes back to the site and the search-all checkbox has been automatically checked, but the user has
            // not yet done a search and so there are no arguments in the query string.
            $searchAllCookie = isset($_COOKIE['SEARCH-ALL']) ? $_COOKIE['SEARCH-ALL'] == 'true' : false;

            // If none of the above apply, default to only searching the contributor index.
            $this->searchAll = false;

            // Test the conditions in precedence order.
            if ($searchAllNever)
                $this->searchAll = false;
            else if ($searchAllAlways)
                $this->searchAll = true;
            else if ($searchAllArg)
                $this->searchAll = $searchAllArg;
            else if ($searchAllCookie)
                $this->searchAll = $searchAllCookie;
        }

        return $this->searchAll;
    }

    public function setIndexName($name)
    {
        $this->indexName = $name;
    }

    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

}