<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

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

    public static function generateContributorStatistics()
    {
        $stats = '';
        $avantElasticsearchClient = new AvantElasticsearchClient();

        if ($avantElasticsearchClient->ready())
        {
            $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();

            // Explicitly specify that the shared index should be queried.
            $indexName = AvantElasticsearch::getNameOfSharedIndex();
            $avantElasticsearchQueryBuilder->setIndexName($indexName);

            $params = $avantElasticsearchQueryBuilder->constructFileStatisticsAggregationParams($indexName);
            $response = $avantElasticsearchClient->search($params);
            if ($response == null)
            {
                $stats = $avantElasticsearchClient->getLastError();
            }
            else
            {
                $audioTotal = 0;
                $documentTotal = 0;
                $imageTotal = 0;
                $itemTotal = 0;
                $videoTotal = 0;

                $rows = '';
                $buckets = $response['aggregations']['contributors']['buckets'];

                // Get all the totals so that we'll know which columns to generate.
                foreach ($buckets as $index => $bucket)
                {
                    $itemCount = $bucket['doc_count'];
                    $itemTotal += $itemCount;

                    $imageCount = intval($bucket['image']['value']);
                    $imageTotal += $imageCount;

                    $documentCount = intval($bucket['document']['value']);
                    $documentTotal += $documentCount;

                    $audioCount = intval($bucket['audio']['value']);
                    $audioTotal += $audioCount;

                    $videoCount = intval($bucket['video']['value']);
                    $videoTotal += $videoCount;
                }

                // Generate the headers
                $header = "<table id='search-stats-table'>";
                $header .= '<tr>';
                $header .= '<td><strong>Organization</strong></td>';
                $header .= '<td><strong>Id</strong></td>';
                $header .= '<td><strong>Items</strong></td>';
                $header .= '<td><strong>Images</strong></td>';
                $header .= '<td><strong>Docs</strong></td>';
                if ($audioTotal > 0)
                {
                    $header .= '<td><strong>Audio</strong></td>';
                }
                if ($videoTotal > 0)
                {
                    $header .= '<td><strong>Video</strong></td>';
                }
                $header .= '</tr>';

                // Generate the rows.
                foreach ($buckets as $index => $bucket)
                {
                    $contributorId = $response['aggregations']['contributor-ids']['buckets'][$index]['key'];

                    $itemCount = $bucket['doc_count'];
                    $imageCount = intval($bucket['image']['value']);
                    $documentCount = intval($bucket['document']['value']);
                    $audioCount = intval($bucket['audio']['value']);
                    $videoCount = intval($bucket['video']['value']);

                    $rows .= '<tr>';
                    $contributor = $bucket['key'];
                    $rows .= "<td>$contributor</td>";
                    $rows .= "<td>$contributorId</td>";
                    $rows .= "<td>$itemCount</td>";
                    $rows .= "<td>$imageCount</td>";
                    $rows .= "<td>$documentCount</td>";
                    if ($audioTotal > 0)
                    {
                        $rows .= "<td>$audioCount</td>";
                    }
                    $rows .= '</tr>';
                    if ($videoTotal > 0)
                    {
                        $rows .= "<td>$videoCount</td>";
                    }
                    $rows .= '</tr>';
                }

                // Generate the Totals row.
                $itemTotal = number_format($itemTotal);
                $totals = '<tr>';
                $totals .= '<td><strong></strong>';
                $totals .= '<td><strong>Totals</strong>';
                $totals .= "<td><strong>$itemTotal</strong></td>";
                $totals .= "<td><strong>$imageTotal</strong></td>";
                $totals .= "<td><strong>$documentTotal</strong></td>";
                if ($audioTotal > 0)
                {
                    $totals .= "<td><strong>$audioTotal</strong></td>";
                }
                if ($videoTotal > 0)
                {
                    $totals .= "<td><strong>$videoTotal</strong></td>";
                }
                $totals .= "</tr>";
                $totals .= '</table>';

                $stats = $header . $rows . $totals;
            }
        }
        return $stats;
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