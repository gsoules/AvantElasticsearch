<?php

require __DIR__ . '/../vendor/autoload.php';
use Elasticsearch\ClientBuilder;

// Count facet values that are blank so that the user can see how many results have no value for a particular field.
// This way the user knows that the facet values shown are not representative of all the results. The text value is
// what gets recorded in the index whereas the substitute is what the user sees. This allows us to change the latter
// without having to reindex. Doing this also makes it easy for administrators to identify missing data.
define('BLANK_FIELD_TEXT', '[blank]');
define('BLANK_FIELD_SUBSTITUTE', 'none');
define('UNMAPPED_FIELD_TEXT', '[unmapped]');

// Used for reporting statistics.
define('MB_BYTES', 1024 * 1024);

class AvantElasticsearch
{
    protected $rootCauseReason = '';

    // Should only be accessed via a getter or setter.
    private $indexName;

    // Used for caching and therefore should not be accessed directly by subclasses.
    private $fieldNamesOfAllElements = array();
    private $fieldNamesOfLocalElements = array();
    private $fieldNamesOfPrivateElements = array();
    private $fieldNamesOfCommonElements = array();

    public function __construct()
    {
        // Set the index name to an empty string rather than null. This way, an attempt to query Elasticsearch with no
        // index will cause an exception. If the index is null, Elasticsearch apparently queries all of the indexes.
        $this->indexName = '';
    }

    public function afterSaveItem($args)
    {
        // This method is called when the admin either saves an existing item or adds a new item to the Omeka database.
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        if ($sharedIndexIsEnabled || $localIndexIsEnabled)
        {
            $item = $args['record'];
            $isSaveAction = $args['insert'] == false;
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $this->updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled, $isSaveAction);
        }
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

    public static function generateContributorStatistics($indexName)
    {
        $stats = '';
        $avantElasticsearchClient = new AvantElasticsearchClient();

        if ($avantElasticsearchClient->ready())
        {
            $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();

            // Explicitly specify that the shared index should be queried.
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
                $header .= '<td class="contributor-table-organization"><strong>Contributor</strong></td>';
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
                    $rows .= "<td class=\"contributor-table-organization\">$contributor</td>";
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

    public function getFieldNamesOfAllElements()
    {
        if (empty($this->fieldNamesOfAllElements))
        {
            // The elements are not cached. Get them from the database.
            $db = get_db();
            $table = "{$db->prefix}elements";

            $sql = "
            SELECT $table.id, $table.name
            FROM $table";

            $elements = $db->query($sql)->fetchAll();
            $unusedElementsData = CommonConfig::getOptionDataForUnusedElements();
            $this->fieldNamesOfAllElements = array();

            foreach ($elements as $element)
            {
                $elementId = $element['id'];

                $ignoreUnused = array_key_exists($elementId, $unusedElementsData);
                if ($ignoreUnused)
                {
                    continue;
                }

                $this->fieldNamesOfAllElements[$elementId] = $this->convertElementNameToElasticsearchFieldName($element['name']);
            }

            asort($this->fieldNamesOfAllElements);
        }

        return $this->fieldNamesOfAllElements;
    }

    public function getFieldNamesOfCommonElements()
    {
        if (empty($this->fieldNamesOfCommonElements))
        {
            $config = AvantElasticsearch::getAvantElasticsearcConfig();
            $elementsList = $config ? $config->common_elements : array();
            $elementNames = array_map('trim', explode(',', $elementsList));
            foreach ($elementNames as $elementName)
            {
                $this->fieldNamesOfCommonElements[] = $this->convertElementNameToElasticsearchFieldName($elementName);
            }
            asort($this->fieldNamesOfCommonElements);
        }

        return $this->fieldNamesOfCommonElements;
    }

    protected function getFieldNamesOfLocalElements()
    {
        if (empty($this->fieldNamesOfLocalElements))
        {
            $allFields = $this->getFieldNamesOfAllElements();
            $commonFields = $this->getFieldNamesOfCommonElements();
            $privateFields = $this->getFieldNamesOfPrivateElements();
            $this->fieldNamesOfLocalElements = array_diff($allFields, $commonFields, $privateFields);
            asort($this->fieldNamesOfLocalElements);
        }

        return $this->fieldNamesOfLocalElements;
    }

    public function getFieldNamesOfPrivateElements()
    {
        if (empty($this->fieldNamesOfPrivateElements))
        {
            // The elements are not cached. Get them from the database.
            $privateElementsData = CommonConfig::getOptionDataForPrivateElements();
            foreach ($privateElementsData as $elementId => $privateElementName)
            {
                $this->fieldNamesOfPrivateElements[$elementId] = $this->convertElementNameToElasticsearchFieldName($privateElementName);
            }
            asort($this->fieldNamesOfPrivateElements);
        }

        return $this->fieldNamesOfPrivateElements;
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

    public function updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled, $isSaveAction = false)
    {
        if ($sharedIndexIsEnabled)
        {
            $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfSharedIndex());
            if ($item->public)
            {
                // Save or add this public item to the shared index.
                $excludePrivateFields = true;
                $avantElasticsearchIndexBuilder->addItemToIndex($item, $excludePrivateFields);
            }
            else
            {
                if ($isSaveAction)
                {
                    // This non-public item is being saved. Attempt to delete it from the shared index. It's an
                    // 'attempt' because we don't know if the item is in the shared index, but if it is, it needs to
                    // get deleted. This logic handles the case where the items was public, but the admin just now
                    // unchecked the public box and saved the item. If that's not the case, the delete has no effect.
                    $failedAttemptOk = true;
                    $avantElasticsearchIndexBuilder->deleteItemFromIndex($item, $failedAttemptOk);
                }
            }
        }

        if ($localIndexIsEnabled)
        {
            // Save or add the item to the local index. Both public and non-public items get saved/added.
            $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfLocalIndex());
            $excludePrivateFields = false;
            $avantElasticsearchIndexBuilder->addItemToIndex($item, $excludePrivateFields);
        }
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
        else
        {
            // Both indexes are enabled.
            $siteId = AvantCommon::queryStringArgOrCookie('site', 'SITE-ID', 0);
            $useSharedIndex = $siteId == 1;
        }

        return $useSharedIndex;
    }
}