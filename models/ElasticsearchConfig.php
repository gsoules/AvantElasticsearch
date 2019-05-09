<?php

define('CONFIG_LABEL_ES_HOST', __('Host'));
define('CONFIG_LABEL_ES_KEY', __('Key'));
define('CONFIG_LABEL_ES_CONTRIBUTOR', __('Contributor'));
define('CONFIG_LABEL_ES_CONTRIBTUOR_ID', __('Contributor Id'));
define('CONFIG_LABEL_ES_REGION', __('Region'));
define('CONFIG_LABEL_ES_SECRET', __('Secret'));
define('CONFIG_LABEL_ES_STANDALONE', __('Standalone Operation'));

class ElasticsearchConfig extends ConfigOptions
{
    const OPTION_ES_CONTRIBUTOR = 'avantelasticsearch_es_contributor';
    const OPTION_ES_CONTRIBUTOR_ID = 'avantelasticsearch_es_contributor_id';
    const OPTION_ES_HOST = 'avantelasticsearch_es_host';
    const OPTION_ES_KEY = 'avantelasticsearch_es_key';
    const OPTION_ES_REGION = 'avantelasticsearch_es_region';
    const OPTION_ES_SECRET = 'avantelasticsearch_es_secret';
    const OPTION_ES_STANDALONE = 'avantelasticsearch_es_standalone';

    public static function getOptionValueForContributor()
    {
        return self::getOptionText(self::OPTION_ES_CONTRIBUTOR);
    }

    public static function getOptionValueForContributorId()
    {
        return self::getOptionText(self::OPTION_ES_CONTRIBUTOR_ID);
    }

    public static function getOptionValueForHost()
    {
        return self::getOptionText(self::OPTION_ES_HOST);
    }

    public static function getOptionValueForKey()
    {
        return self::getOptionText(self::OPTION_ES_KEY);
    }

    public static function getOptionValueForRegion()
    {
        return self::getOptionText(self::OPTION_ES_REGION);
    }

    public static function getOptionValueForSecret()
    {
        return self::getOptionText(self::OPTION_ES_SECRET);
    }

    public static function saveConfiguration()
    {
        self::saveOptionDataForHost();
        self::saveOptionDataForKey();
        self::saveOptionDataForContributor();
        self::saveOptionDataForContributorId();
        self::saveOptionDataForRegion();
        self::saveOptionDataForSecret();
    }

    public static function saveOptionDataForContributor()
    {
        self::saveOptionText(self::OPTION_ES_CONTRIBUTOR , CONFIG_LABEL_ES_CONTRIBUTOR);
    }

    public static function saveOptionDataForContributorId()
    {
        $optionName = self::OPTION_ES_CONTRIBUTOR_ID;
        $optionLabel = CONFIG_LABEL_ES_CONTRIBTUOR_ID;
        $value = self::getOptionText($optionName);
        $value = strtolower($value);
        self::errorIfEmpty($value, $optionName, $optionLabel);
        $strippedValue = preg_replace('/[^a-z]/', '', $value);
        $hasInvalidCharacters = $strippedValue != $value;
        self::errorIf(strlen($value) < 3 || strlen($value) > 6 || $hasInvalidCharacters, $optionLabel, __('The value does not satisfy the rules for a contributor Id'));
        set_option($optionName, $value);

        set_option(self::OPTION_ES_STANDALONE, intval($_POST[self::OPTION_ES_STANDALONE]));

    }

    public static function saveOptionDataForHost()
    {
        self::saveOptionText(self::OPTION_ES_HOST , CONFIG_LABEL_ES_HOST);
    }

    public static function saveOptionDataForKey()
    {
        self::saveOptionText(self::OPTION_ES_KEY , CONFIG_LABEL_ES_KEY);
    }

    public static function saveOptionDataForRegion()
    {
        self::saveOptionText(self::OPTION_ES_REGION , CONFIG_LABEL_ES_REGION);
    }

    public static function saveOptionDataForSecret()
    {
        self::saveOptionText(self::OPTION_ES_SECRET , CONFIG_LABEL_ES_SECRET);
    }
}