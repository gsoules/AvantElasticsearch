<?php
$view = get_view();

$exportFile = ElasticsearchConfig::getOptionValueForExportFile();
$host = ElasticsearchConfig::getOptionValueForHost();
$key = ElasticsearchConfig::getOptionValueForKey();
$owner = ElasticsearchConfig::getOptionValueForOwner();
$ownerId = ElasticsearchConfig::getOptionValueForOwnerId();
$port = ElasticsearchConfig::getOptionValueForPort();
$region = ElasticsearchConfig::getOptionValueForRegion();
$scheme = ElasticsearchConfig::getOptionValueForScheme();
$secret = ElasticsearchConfig::getOptionValueForSecret();

?>

<style>
    .error{color:red;font-size:16px;}
    .storage-engine {color:#9D5B41;margin-bottom:24px;font-weight:bold;}
</style>

<div class="plugin-help learn-more">
    <a href="https://github.com/gsoules/AvantElasticsearch" target="_blank">Learn about this plugin</a>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_OWNERID; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Identification for this organizaton (3 to 6 lower case letters a-z)'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_OWNERID, $ownerId); ?>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo CONFIG_LABEL_ES_OWNER; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Organization name as it should be shown for the owner of its data'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_OWNER, $owner); ?>
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
        <label><?php echo CONFIG_LABEL_ES_SCHEME; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Scheme e.g. https'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_SCHEME, $scheme); ?>
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
        <label><?php echo CONFIG_LABEL_ES_PORT; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Port number e.g. 443'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_PORT, $port); ?>
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
        <label><?php echo CONFIG_LABEL_ES_EXPORT_FILE; ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Name of file used to store exported data'); ?></p>
        <?php echo $view->formText(ElasticsearchConfig::OPTION_ES_EXPORT_FILE, $exportFile); ?>
    </div>
</div>
