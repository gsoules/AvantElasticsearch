<?php

define('CONFIG_LABEL_ES_EXPORT_FILE',  __('Export Filename'));
define('CONFIG_LABEL_ES_HOST', __('Host'));
define('CONFIG_LABEL_ES_KEY', __('Key'));
define('CONFIG_LABEL_ES_OWNER', __('Owner Id'));
define('CONFIG_LABEL_ES_PORT', __('Port'));
define('CONFIG_LABEL_ES_REGION', __('Region'));
define('CONFIG_LABEL_ES_SCHEME', __('Scheme'));
define('CONFIG_LABEL_ES_SECRET', __('Secret'));

class ElasticsearchConfig extends ConfigOptions
{
    const OPTION_ES_EXPORT_FILE = 'avantelasticsearch_es_export_file';
    const OPTION_ES_HOST = 'avantelasticsearch_es_host';
    const OPTION_ES_KEY = 'avantelasticsearch_es_key';
    const OPTION_ES_OWNER = 'avantelasticsearch_es_owner_id';
    const OPTION_ES_PORT = 'avantelasticsearch_es_port';
    const OPTION_ES_REGION = 'avantelasticsearch_es_region';
    const OPTION_ES_SCHEME = 'avantelasticsearch_es_scheme';
    const OPTION_ES_SECRET = 'avantelasticsearch_es_secret';

    public static function getOptionValueForExportFile()
    {
        return self::getOptionText(self::OPTION_ES_EXPORT_FILE);
    }

    public static function getOptionValueForHost()
    {
        return self::getOptionText(self::OPTION_ES_HOST);
    }

    public static function getOptionValueForKey()
    {
        return self::getOptionText(self::OPTION_ES_KEY);
    }

    public static function getOptionValueForOwner()
    {
        return self::getOptionText(self::OPTION_ES_OWNER);
    }

    public static function getOptionValueForPort()
    {
        return self::getOptionText(self::OPTION_ES_PORT);
    }

    public static function getOptionValueForRegion()
    {
        return self::getOptionText(self::OPTION_ES_REGION);
    }

    public static function getOptionValueForScheme()
    {
        return self::getOptionText(self::OPTION_ES_SCHEME);
    }

    public static function getOptionValueForSecret()
    {
        return self::getOptionText(self::OPTION_ES_SECRET);
    }

    public static function saveConfiguration()
    {
        self::saveOptionDataForExportFile();
        self::saveOptionDataForHost();
        self::saveOptionDataForKey();
        self::saveOptionDataForOwner();
        self::saveOptionDataForPort();
        self::saveOptionDataForRegion();
        self::saveOptionDataForScheme();
        self::saveOptionDataForSecret();
    }

    public static function saveOptionDataForExportFile()
    {
        self::saveOptionText(self::OPTION_ES_EXPORT_FILE , CONFIG_LABEL_ES_EXPORT_FILE);
    }

    public static function saveOptionDataForHost()
    {
        self::saveOptionText(self::OPTION_ES_HOST , CONFIG_LABEL_ES_HOST);
    }

    public static function saveOptionDataForKey()
    {
        self::saveOptionText(self::OPTION_ES_KEY , CONFIG_LABEL_ES_KEY);
    }

    public static function saveOptionDataForOwner()
    {
        $optionName = self::OPTION_ES_OWNER;
        $optionLabel = CONFIG_LABEL_ES_OWNER;
        $value = self::getOptionText($optionName);
        self::errorIfEmpty($value, $optionName, $optionLabel);
        $strippedValue = strtolower(preg_replace('/[^a-z]/', '', $value));
        $hasInvalidCharacters = $strippedValue != $value;
        self::errorIf(strlen($value) < 3 || strlen($value) > 6 || $hasInvalidCharacters, $optionLabel, __('The value does not satisfy the rules for an owner Id'));
        set_option($optionName, $value);
    }

    public static function saveOptionDataForPort()
    {
        self::saveOptionText(self::OPTION_ES_PORT , CONFIG_LABEL_ES_PORT);
    }

    public static function saveOptionDataForRegion()
    {
        self::saveOptionText(self::OPTION_ES_REGION , CONFIG_LABEL_ES_REGION);
    }

    public static function saveOptionDataForScheme()
    {
        self::saveOptionText(self::OPTION_ES_SCHEME , CONFIG_LABEL_ES_SCHEME);
    }

    public static function saveOptionDataForSecret()
    {
        self::saveOptionText(self::OPTION_ES_SECRET , CONFIG_LABEL_ES_SECRET);
    }
}