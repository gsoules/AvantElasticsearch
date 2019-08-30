<?php

define('AVANTELASTICSEARCH_PLUGIN_DIR', dirname(__FILE__));

class AvantElasticsearchPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $item;

    protected $_hooks = array(
        'admin_head',
        'after_delete_item',
        'after_save_item',
        'config',
        'config_form',
        'define_routes',
        'public_footer',
        'public_head'
    );

    protected $_filters = array(
        'admin_navigation_main'
    );

    protected function AvantSearchUsesElasticsearch()
    {
        // Determine if the installed version of AvantSearch has support for Elasticsearch, and if so, whether it has
        // Elasticsearch enabled. This method is required for development and testing purposes in situations where this
        // AvantElasticsearch plugin is installed, but the AvantSearch plugin either has not yet been upgraded to the
        // version that supports Elasticsearch, or it has Elasticsearch turned off for testing/debuging.
        $avantSearchSupportsElasticsearch = method_exists('AvantSearch', 'useElasticsearch');
        return $avantSearchSupportsElasticsearch && AvantSearch::useElasticsearch();
    }

    public function filterAdminNavigationMain($nav)
    {
        $user = current_user();
        if ($user->role == 'super')
        {
            $nav[] = array(
                'label' => __('Elasticsearch'),
                'uri' => url('elasticsearch/indexing')
            );
        }
        return $nav;
    }

    public function hookAdminHead($args)
    {
        queue_css_file('avantelasticsearch-admin');
    }

    public function hookAfterDeleteItem($args)
    {
        // Determine which indexes are enabled.
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        if ($sharedIndexIsEnabled || $localIndexIsEnabled)
        {
            $item = $args['record'];
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

    public function hookAfterSaveItem($args)
    {
        if (plugin_is_active('AvantS3'))
        {
            // Let AvantS3's after_save_item hook handle the save. This is to ensure that AvantS3 does its
            // work first, e.g. to attach a PDF file, before AvantElasticsearch does its work, e.g. index
            // an item's PDF file attachments. If we don't this, a newly attached PDF won't get indexed until
            // the next time an item is saved.
            return;
        }

        $avantElasticsearch = new AvantElasticsearch();
        $avantElasticsearch->afterSaveItem($args);
    }

    public function hookConfig()
    {
        ElasticsearchConfig::saveConfiguration();
    }

    public function hookConfigForm()
    {
        require dirname(__FILE__) . '/config_form.php';
    }

    public function hookDefineRoutes($args)
    {
        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'routes.ini', 'routes'));
    }

    public function hookPublicFooter($args)
    {
        if (!$this->AvantSearchUsesElasticsearch())
            return;

        // Emit the Javascript for Elasticsearch suggestions while typing in the search box.
        $avantElasticsearchQueryBuilder = new AvantElasticsearchQueryBuilder();
        $activeIndexName = $avantElasticsearchQueryBuilder->getNameOfActiveIndex();
        $findUrl = url('find?query=');
        $suggestUrl = 'https://' . ElasticsearchConfig::getOptionValueForHost();
        $suggestUrl .= '/' . $activeIndexName . '/_search';
        $query = $avantElasticsearchQueryBuilder->constructSuggestQueryParams(1, 12);

        echo get_view()->partial('avantelasticsearch-script.php', array(
            'query' => $query,
            'suggestUrl' => $suggestUrl,
            'findUrl' => $findUrl));
    }

    public function hookPublicHead($args)
    {
        // Needed to support autocomplete in the simple search textbox.
        queue_css_file('jquery-ui');
        queue_css_file('avantelasticsearch');

        if ($this->AvantSearchUsesElasticsearch())
            queue_js_file('avantelasticsearch-script');
    }
}
