<?php
$view = get_view();

$contributor = ElasticsearchConfig::getOptionValueForContributor();
$contributorId = ElasticsearchConfig::getOptionValueForContributorId();
$host = ElasticsearchConfig::getOptionValueForHost();
$key = ElasticsearchConfig::getOptionValueForKey();
$region = ElasticsearchConfig::getOptionValueForRegion();
$secret = ElasticsearchConfig::getOptionValueForSecret();

$localIndexName = AvantElasticsearch::getNameOfLocalIndex();
$sharedIndexName = AvantElasticsearch::getNameOfSharedIndex();

$healthOk = false;

$avantElasticserachClient = new AvantElasticsearchClient();
if ($avantElasticserachClient->ready())
{
    $health = $avantElasticserachClient->getHealth();
    $healthReport = $health['message'];
    $healthOk = $health['ok'];
}
else
{
    if (!empty($host) && !empty($region) && !empty($key) && !empty($secret))
        $healthReport = __('Unable to create AvantElasticsearchClient');
}
$healthReportClass = ' class="health-report-' . ($healthOk ? 'ok' : 'error') . '"';

?>

<style>
    .error{color:red;font-size:16px;}
    .storage-engine {color:#9D5B41;margin-bottom:24px;font-weight:bold;}
</style>

<div class="plugin-help learn-more">
    <a href="https://digitalarchive.us/plugins/avantelasticsearch/" target="_blank">Learn about this plugin</a>
</div>

<?php echo "<div$healthReportClass>$healthReport<br/><br/></div>"; ?>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_CONTRIBTUOR_ID; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Identification for this organizaton (3 to 6 lower case letters a-z)'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_CONTRIBUTOR_ID, $contributorId); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_CONTRIBUTOR; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Organization name as it should be shown for the contributor of its data'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_CONTRIBUTOR, $contributor); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_HOST; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Example: search-something-xxxxxxxxxxxx.us-east-2.es.amazonaws.com'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_HOST, $host); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_REGION; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('AWS server region'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_REGION, $region); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_KEY; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('AWS Access Key Id'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_KEY, $key); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_SECRET; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('AWS Secret Access Key'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_SECRET, $secret); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_LOCAL; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("Update local index '%s' after Add, Save, or Delete item", $localIndexName); ?></p>
        <?php echo $view->formCheckbox(ElasticsearchConfig::OPTION_ES_LOCAL, true, array('checked' => (boolean)get_option(ElasticsearchConfig::OPTION_ES_LOCAL))); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_SHARE; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __("Update shared index '%s' after Add, Save, or Delete item", $sharedIndexName); ?></p>
        <?php echo $view->formCheckbox(ElasticsearchConfig::OPTION_ES_SHARE, true, array('checked' => (boolean)get_option(ElasticsearchConfig::OPTION_ES_SHARE))); ?>
    </div>
</div>
