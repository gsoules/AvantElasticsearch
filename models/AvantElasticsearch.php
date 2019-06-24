<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

// Use this constant as newline character when creating and reading an Elasticsearch document. Don't use PHP_EOL
// because its value differs between Linux (\n) and Windows (\r\n). The difference should not matter in production
// which is Linux, but it's a problem during development when using both platforms because if you create the document
// on one platform, you can't read it correctly on the other.
define ('ES_DOCUMENT_EOL', "\n");

// Count facet values that are blank so that the user can see how many results have no value for a particular field.
// This way the user knows that the facet values shown are not representative of all the results. The text value is
// what gets recorded in the index whereas the substitute is what the user sees. This allows us to change the latter
// without having to reindex. Doing this also makes it easy for administrators to identify missing data.
define('BLANK_FIELD_TEXT', '[blank]');
define('BLANK_FIELD_SUBSTITUTE', 'none');

// Used for reporting statistics.
define('MB_BYTES', 1024 * 1024);

class AvantElasticsearch
{
    protected $indexOnlyPublicElements = true;
    protected $rootCauseReason = '';

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

    public static function getAvantElasticsearcConfig()
    {
        try
        {
            $configFile = AVANTELASTICSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'config.ini';
            return new Zend_Config_Ini($configFile, 'config');
        }
        catch (Exception $e)
        {
            return null;
        }
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
                    $this->rootCauseReason = $rootCause[0]->reason;
                    $errorReason = $error->reason;
                    $message = "Type: $error->type<br/>Reason: $errorReason<br/>Root cause: $this->rootCauseReason";
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

    public function getElementsUsedByThisInstallation($public = false)
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

    public function getNameOfActiveIndex()
    {
        // Return the name of the index that is currently set for this object.
        return $this->indexName;
    }

    public static function getNameOfLocalIndex()
    {
       return ElasticsearchConfig::getOptionValueForContributorId();
    }

    public static function getNameOfSharedIndex()
    {
        $configFile = AVANTELASTICSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'config.ini';
        $configuration = new Zend_Config_Ini($configFile, 'config');
        return $configuration->shared_index_name;
    }

    protected function getSharedIndexFieldNames()
    {
        $config = AvantElasticsearch::getAvantElasticsearcConfig();
        $elementsList = $config ? $config->shared_elements : array();
        $elementNames = array_map('trim', explode(',', $elementsList));
        $fieldNames = array();
        foreach ($elementNames as $elementName)
        {
            $fieldNames[] = $this->convertElementNameToElasticsearchFieldName($elementName);
        }
        return $fieldNames;
    }

    public function getYearFromDate($dateText)
    {
        $year = '';
        if (preg_match("/^.*(\d{4}).*$/", $dateText, $matches))
        {
            $year = $matches[1];
        }
        return $year;
    }

    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    public function setIndexName($name)
    {
        $this->indexName = $name;
    }

    public static function useSharedIndexForQueries()
    {
        // Determine if queries should be performed on the shared index. If not, the local index will be used.

        // Determine which indexes are enabled.
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        // Test all conditions in precedence order, returning when a condition is true.
        if (!($sharedIndexIsEnabled || $localIndexIsEnabled))
        {
            // Both indexes are disabled. This condition is meaningful when the AvantElasticsearch plugin is installed
            // for the purpose of exporting the local data to a file, but the installation is still using SQL search.
            // Normally this method would not get called in that situation, except during development/debugging, in
            // which case the default is to search only the shared index since a local index might not yet exist.
            $useSharedIndex = true;
        }
        else if (!$sharedIndexIsEnabled)
        {
            // Search the local index because the shared index is disabled.
            $useSharedIndex = false;
        }
        else if (!$localIndexIsEnabled)
        {
            // Search the shared index because the local index is disabled.
            $useSharedIndex = true;
        }
        else if (isset($_REQUEST['site']))
        {
            $useSharedIndex = intval($_REQUEST['site']) == 1;
        }
        else
        {
            // Both indexes are enabled, but there is no query string arg or cookie. Default to searching the local index.
            $useSharedIndex = false;
        }

        return $useSharedIndex;
    }
}