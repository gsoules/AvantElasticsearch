<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

class AvantElasticsearch
{
    protected $docIndex;
    protected $elementsForIndex = array();
    protected $ignorePrivateElements = true;

    public function __construct()
    {
        $this->docIndex = $this->getElasticsearchIndexName();
    }

    protected function catentateElementTexts($texts)
    {
        $elementTexts = '';
        foreach ($texts as $text) {
            if (!empty($elementTexts))
            {
                $elementTexts .= PHP_EOL;
            }
            $elementTexts .= $text;
        }
        return $elementTexts;
    }

    public function convertElementNameToElasticsearchFieldName($elementName)
    {
        // Convert the element name to lowercase and strip away spaces and other non-alphanumberic characters
        // as required by Elasticsearch syntax.
        return strtolower(preg_replace('/[^a-zA-Z0-9-_]/', '', $elementName));
    }

    public function getDocumentMappingType()
    {
        return '_doc';
    }

    public function getElasticsearchIndexName()
    {
        return get_option('avantsearch_elasticsearch_index');
    }

    public function getDocumentIdForItem($item)
    {
        // Create an id that is unique among all organizations that have items in the index.
        // The item Id alone is not sufficient since multiple organizations may have an item with
        // that Id. However, the item Id combined with the item's owner Id is unique.

        $ownerId = ElasticsearchConfig::getOptionValueForOwnerId();
        $documentId = "$ownerId-$item->id";
        return $documentId;
    }

    public function getElasticsearchExceptionMessage(Exception $e)
    {
        $message = $e->getMessage();

        if ($this->isJson($message))
        {
            $message = json_decode($message);
            if (is_object($message))
            {
                if (isset($message->error))
                {
                    $error = $message->error;
                    $message = "Type: $error->type<br/>Reason: $error->reason";
                }
                else if (isset($message->message))
                {
                    $message = $message->message;
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

    public function getElementsUsedByThisInstallation($ignorePrivate = true)
    {
        if (empty($this->elementsForIndex) || $this->ignorePrivateElements != $ignorePrivate)
        {
            $this->ignorePrivateElements = $ignorePrivate;

            $table = get_db()->getTable('Element');
            $select = $table->getSelect();
            $this->elementsForIndex = $table->fetchObjects($select);

            $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
            $unusedElementsData = CommonConfig::getOptionDataForUnusedElements();

            foreach ($this->elementsForIndex as $elementName => $element)
            {
                $elementId = $element->id;
                $ingoreUnused = array_key_exists($elementId, $unusedElementsData);
                $ignore = $ingoreUnused || ($this->ignorePrivateElements && array_key_exists($elementId, $privateElementsData));
                if ($ignore)
                {
                    unset($this->elementsForIndex[$elementName]);
                }
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