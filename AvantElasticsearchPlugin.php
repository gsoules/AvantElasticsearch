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
        if (AvantCommon::importingHybridItem())
        {
            // Ignore this call when AvantHybrid is deleting a hybrid item. The call gets made indirectly via the
            // hookAfterDeleteItem method for the AvantElasticSearch plugin when HybridImport deletes the hybrid's
            // Omeka item. For some reason, in that situation, $item exists, but is not valid and therefor this method
            // cannot be used to delete the Hybrid item's Elasticsearch index entries. Instead, HybridImport calls this
            // method directly before it deletes the Omeka item.
            return;
        }

        AvantElasticsearch::deleteItemFromIndexes($args['record']);
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
