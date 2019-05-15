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
        if ($this->AvantSearchUsesElasticsearch() || get_option(ElasticsearchConfig::OPTION_ES_STANDALONE))
        {
            $item = $args['record'];

            // Delete the item from the shared index.
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $avantElasticsearchIndexBuilder->setIndexName('omeka');
            $avantElasticsearchIndexBuilder->deleteItemFromIndex($item);

            // Delete the item from the contributor's index.
//            $avantElasticsearchIndexBuilder->setIndexName(<contributor-id>);
//            $avantElasticsearchIndexBuilder->deleteItemFromIndex($item);
        }
    }

    public function hookAfterSaveItem($args)
    {
        // This method is called when the admin either saves an existing item or adds a new item to the Omeka database.

        if ($this->AvantSearchUsesElasticsearch() || get_option(ElasticsearchConfig::OPTION_ES_STANDALONE))
        {
            $item = $args['record'];

            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();
            $avantElasticsearchIndexBuilder->setIndexName('omeka');
            if ($item->public)
            {
                // Save or add the item to the shared index.
                $avantElasticsearchIndexBuilder->addItemToIndex($item);
            }
            else
            {
                // Attempt to delete this item from the shared index in case it was public and just got saved as
                // not public. If the item was already not public, then it won't be in the shared index and the
                // delete will fail, but that's okay.
                $okIfMissing = true;
                $avantElasticsearchIndexBuilder->deleteItemFromIndex($item, $okIfMissing);
            }

            // Save or add the item to the contributor's  index.
//            $avantElasticsearchIndexBuilder->setIndexName(<contributor-id>);
//            $avantElasticsearchIndexBuilder->addItemToIndex($item);
        }
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
        if ($this->AvantSearchUsesElasticsearch())
        {
            // Emit the Javascript for Elasticsearch suggestions while typing in the search box.
            echo get_view()->partial('avantelasticsearch-script.php');
        }
    }

    public function hookPublicHead($args)
    {
        // Needed to support autocomplete in the simple search textbox.
        queue_css_file('jquery-ui');
        queue_css_file('avantelasticsearch');
    }
}
