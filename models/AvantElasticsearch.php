<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

define('MB_BYTES', 1024 * 1024);

class AvantElasticsearch
{
    protected $documentIndexName;
    protected $indexOnlyPublicElements = true;
    
    // Used for caching and therefore should not be accessed directly by subclasses.
    private $elementsForIndex = array();

    public function __construct()
    {
        $this->documentIndexName = $this->getElasticsearchIndexName();
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

    public function getElasticsearchIndexName()
    {
        return 'omeka';
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
                else
                {
                    // Don't know what kind of object this is so just return the raw message.
                    $message = $e->getMessage();
                }
            }
        }
        return $message;
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

            $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
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

    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

}