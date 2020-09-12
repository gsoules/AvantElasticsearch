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
    protected $rootCauseReason = '';

    // Should only be accessed via a getter or setter.
    private $indexName;

    // Used for caching and therefore should not be accessed directly by subclasses.
    private $fieldNamesOfAllElements = array();
    private $fieldNamesOfCoreElements = array();
    private $fieldNamesOfLocalElements = array();
    private $fieldNamesOfPrivateElements = array();

    public function __construct()
    {
        // Set the index name to an empty string rather than null. This way, an attempt to query Elasticsearch with no
        // index will cause an exception. If the index is null, Elasticsearch apparently queries all of the indexes.
        $this->indexName = '';
    }

    public function afterSaveItem($args)
    {
        // This method is called when the admin either saves an existing item or adds a new item to the Omeka database.

        if (AvantCommon::importingHybridItem())
        {
            // Ignore this call when AvantHybrid is saving an because HybridImport calls updateIndexForItem directly.
            return;
        }

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

    public static function deleteItemFromIndexes($item)
    {
        // Determine which indexes are enabled.
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        if ($sharedIndexIsEnabled || $localIndexIsEnabled)
        {
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

            if ($sharedIndexIsEnabled && $item->public)
            {
                // Delete the public item from the shared index. A non-public item should not be in the index.
                $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfSharedIndex());
                $avantElasticsearchIndexBuilder->deleteItemFromIndex($item);
            }

            if ($localIndexIsEnabled)
            {
                // Delete the item from the local index.
                $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfLocalIndex());
                $avantElasticsearchIndexBuilder->deleteItemFromIndex($item);
            }
        }
    }

    public static function generateContributorStatistics($indexName)
    {
        $contributorCount = 0;
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
                $relationshipTotal = 0;
                $weightTotal = 0;

                $buckets = $response['aggregations']['contributors']['buckets'];
                $contributorCount = count($buckets);

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

                    // Divide the relationship count by two to reflect that item has 1/2 of the relationship with another item.
                    $relationshipCount = intval($bucket['relationship']['value']);
                    $relationshipCount = intval($relationshipCount / 2);
                    $relationshipTotal += $relationshipCount;

                    $weightTotal += $itemCount + $imageCount + $documentCount + $relationshipCount + $audioCount + $videoCount;
                }

                // Generate the header row.
                $headerHtml = "<table id='search-stats-table'>";
                $headerHtml .= "<tr id='search-stats-table-header'>";
                $headerHtml .= '<td class="contributor-table-organization"><strong>Contributor</strong></td>';
                $headerHtml .= '<td><strong>ID</strong></td>';
                $headerHtml .= '<td><strong>Items</strong></td>';
                $headerHtml .= '<td>Attached<br/><strong>Images</strong></td>';
                $headerHtml .= '<td>Attached<br/><strong>Documents</strong></td>';
                if ($audioTotal > 0)
                    $headerHtml .= '<td>Attached<br/><strong>Audio</strong></td>';
                if ($videoTotal > 0)
                    $headerHtml .= '<td>Attached<br/><strong>Video</strong></td>';
                $headerHtml .= '<td><strong>Relationships</strong></td>';
                $headerHtml .= '<td><strong>Total</strong></td>';
                $headerHtml .= '</tr>';

                $rows = array();

                // Generate an array of rows of HTML.
                foreach ($buckets as $index => $bucket)
                {
                    $row = '';
                    $contributorId = $response['aggregations']['contributor-ids']['buckets'][$index]['key'];

                    // Get the row's column values from the bucket.
                    $itemCount = $bucket['doc_count'];
                    $imageCount = intval($bucket['image']['value']);
                    $documentCount = intval($bucket['document']['value']);
                    $audioCount = intval($bucket['audio']['value']);
                    $videoCount = intval($bucket['video']['value']);

                    // Divide the relationship count by two to reflect that item has 1/2 of the relationship with another item.
                    $relationshipCount = intval($bucket['relationship']['value']);
                    $relationshipCount = intval($relationshipCount / 2);

                    $weightCount = $itemCount + $imageCount + $documentCount + $audioCount + $videoCount + $relationshipCount;
                    $weight = $weightCount;

                    // Format the totals to include a comma thousands separator.
                    $itemCount = number_format($itemCount);
                    $imageCount = number_format($imageCount);
                    $documentCount = number_format($documentCount);
                    $relationshipCount = number_format($relationshipCount);
                    $audioCount = number_format($audioCount);
                    $videoCount = number_format($videoCount);
                    $weightCount = number_format($weightCount);

                    // Generate the row.
                    $row .= '<tr>';
                    $contributor = $bucket['key'];
                    $row .= "<td class=\"contributor-table-organization\">$contributor</td>";
                    $row .= "<td>$contributorId</td>";
                    $row .= "<td>$itemCount</td>";
                    $row .= "<td>$imageCount</td>";
                    $row .= "<td>$documentCount</td>";
                    if ($audioTotal > 0)
                        $row .= "<td>$audioCount</td>";
                    if ($videoTotal > 0)
                        $row .= "<td>$videoCount</td>";
                    $row .= "<td>$relationshipCount</td>";
                    $row .= "<td>$weightCount</td>";
                    $row .= '</tr>';

                    // Add the row's weight and HTML to an array.
                    $rows[] = array($weight, $row);
                }

                // Sort the array descending based on its weight.
                usort($rows, function($a, $b){ return $a[0] < $b[0]; });

                // Combine the row HTML into a single string.
                $rowsHtml = '';
                foreach ($rows as $row)
                    $rowsHtml .= $row[1];

                // Format the totals to include a comma thousands separator.
                $itemTotal = number_format($itemTotal);
                $imageTotal = number_format($imageTotal);
                $documentTotal = number_format($documentTotal);
                $relationshipTotal = number_format($relationshipTotal);
                $audioTotal = number_format($audioTotal);
                $videoTotal = number_format($videoTotal);
                $weightTotal = number_format($weightTotal);

                // Generate the Totals row.
                $totalsHtml = '<tr>';
                $totalsHtml .= '<td><strong></strong>';
                $totalsHtml .= '<td><strong>Totals</strong>';
                $totalsHtml .= "<td><strong>$itemTotal</strong></td>";
                $totalsHtml .= "<td><strong>$imageTotal</strong></td>";
                $totalsHtml .= "<td><strong>$documentTotal</strong></td>";
                if ($audioTotal > 0)
                    $totalsHtml .= "<td><strong>$audioTotal</strong></td>";
                if ($videoTotal > 0)
                    $totalsHtml .= "<td><strong>$videoTotal</strong></td>";
                $totalsHtml .= "<td><strong>$relationshipTotal</strong></td>";
                $totalsHtml .= "<td><strong>$weightTotal</strong></td>";
                $totalsHtml .= "</tr>";
                $totalsHtml .= '</table>';

                $stats = $headerHtml . $rowsHtml . $totalsHtml;
            }
        }
        return array($contributorCount, $stats);
    }

    public static function getAvantElasticsearcConfig()
    {
        try
        {
            $configFile = BASE_DIR . DIRECTORY_SEPARATOR . 'es.ini';
            return new Zend_Config_Ini($configFile, 'config');
        }
        catch (Exception $e)
        {
            throw new Exception("Could not read AvantElasticsearch config file: $configFile");
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

    public function getFieldNamesOfCoreElements()
    {
        if (empty($this->fieldNamesOfCoreElements))
        {
            $config = AvantElasticsearch::getAvantElasticsearcConfig();
            if ($config)
            {
                $elementsList = $config ? $config->common_elements : array();
                $elementNames = array_map('trim', explode(',', $elementsList));
                foreach ($elementNames as $elementName)
                {
                    $this->fieldNamesOfCoreElements[] = $this->convertElementNameToElasticsearchFieldName($elementName);
                }
                asort($this->fieldNamesOfCoreElements);
            }
            else
            {
                throw new Exception('No core element names are configured');
            }
        }

        return $this->fieldNamesOfCoreElements;
    }

    protected function getFieldNamesOfLocalElements()
    {
        if (empty($this->fieldNamesOfLocalElements))
        {
            $allFields = $this->getFieldNamesOfAllElements();
            $coreFields = $this->getFieldNamesOfCoreElements();
            $privateFields = $this->getFieldNamesOfPrivateElements();
            $this->fieldNamesOfLocalElements = array_diff($allFields, $coreFields, $privateFields);
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
        $configuration = self::getAvantElasticsearcConfig();
        return $configuration->shared_index_name;
    }

    public static function getNewSharedIndexAllowed()
    {
        $configuration = self::getAvantElasticsearcConfig();
        return $configuration->new_shared_index_allowed == true;
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

    public static function handleHealthCheck($siteId)
    {
        $db = get_db();
        $table = "{$db->prefix}items";
        $sql = "SELECT COUNT(*) FROM $table";
        $sqlItemsCount = $db->fetchOne($sql);

        $indexItemsCount = 0;

        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;
        if ($localIndexIsEnabled)
        {
            $avantElasticsearchClient = new AvantElasticsearchClient();
            if ($avantElasticsearchClient->ready())
            {
                $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();

                // Explicitly specify that the shared index should be queried.
                $indexName = AvantElasticsearch::getNameOfLocalIndex();
                $avantElasticsearchQueryBuilder->setIndexName($indexName);

                $params = $avantElasticsearchQueryBuilder->constructFileStatisticsAggregationParams($indexName);
                $response = $avantElasticsearchClient->search($params);
                if ($response)
                    $indexItemsCount = $response["hits"]["total"];
            }
        }

        if ($sqlItemsCount == $indexItemsCount)
        {
            $status = "PASS: SQL and Index both contain $indexItemsCount items";
        }
        else
        {
            $subject = "Health Check FAILED for $siteId";
            $status = "FAIL: SQL:$sqlItemsCount Index:$indexItemsCount";
            AvantCommon::sendEmailToAdministrator('daus cron', $subject, $status);
        }

        return $status;
    }

    public static function handleRemoteRequest($action, $siteId, $password)
    {
        if (AvantElasticsearch::remoteRequestIsValid($siteId, $password))
        {
            switch ($action)
            {
                case 'es-health-check':
                    $response = AvantElasticsearch::handleHealthCheck($siteId);
                    break;

                default:
                    $response = 'Unsupported AvantElasticsearch action: ' . $action;
                    break;
            }
        }
        else
        {
            $response = '';
        }

        return $response;
    }

    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    protected function isSharedIndexVocabularyField($fieldName)
    {
        if (!plugin_is_active('AvantVocabulary'))
            return false;

        // Determine if the field uses the Common Vocabulary.
        $vocabularyFields = AvantVocabulary::getVocabularyFields();
        foreach ($vocabularyFields as $vocabularyFieldName => $kind)
        {
            if ($fieldName == $this->convertElementNameToElasticsearchFieldName($vocabularyFieldName))
                return true;
        }
        return false;
    }

    public static function remoteRequestIsValid($siteId, $password)
    {
        // Use the last six characters of the Elasticsearch key as the password for remote access to AvantVocabulary.
        // This is simpler/safer than the remote caller having to know an Omeka user name and password. Though the
        // key is public anyway, using just the tail end of it means the caller does not know the entire key.
        $key = ElasticsearchConfig::getOptionValueForKey();
        $keySuffix = substr($key, strlen($key) - 6);
        return $password == $keySuffix && $siteId == ElasticsearchConfig::getOptionValueForContributorId();
    }

    public function setIndexName($name)
    {
        $this->indexName = $name;
    }

    public function  updateIndexForItem($item, $avantElasticsearchIndexBuilder, $sharedIndexIsEnabled, $localIndexIsEnabled, $isSaveAction = false)
    {
        if ($sharedIndexIsEnabled)
        {
            $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfSharedIndex());
            if ($item->public)
            {
                // Save or add this public item to the shared index.
                $excludePrivateFields = true;
                $avantElasticsearchIndexBuilder->addItemToIndex($item, true, $excludePrivateFields);
            }
            else
            {
                if ($isSaveAction)
                {
                    // This non-public item is being saved. Attempt to delete it from the shared index. It's an
                    // 'attempt' because we don't know if the item is in the shared index, but if it is, it needs to
                    // get deleted. This logic handles the case where the items was public, but the admin just now
                    // unchecked the public box and saved the item. If that's not the case, the delete has no effect.
                    $missingDocumentExceptionOk = true;
                    $avantElasticsearchIndexBuilder->deleteItemFromIndex($item, $missingDocumentExceptionOk);
                }
            }
        }

        if ($localIndexIsEnabled)
        {
            // Save or add the item to the local index. Both public and non-public items get saved/added.
            $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfLocalIndex());
            $excludePrivateFields = false;
            $avantElasticsearchIndexBuilder->addItemToIndex($item, false, $excludePrivateFields);
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