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

            if ($sharedIndexIsEnabled)
            {
                // Delete the item from the shared index.
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
        // This method is called when the admin either saves an existing item or adds a new item to the Omeka database.

        // Determine which indexes are enabled.
        $sharedIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_SHARE) == true;
        $localIndexIsEnabled = (bool)get_option(ElasticsearchConfig::OPTION_ES_LOCAL) == true;

        if ($sharedIndexIsEnabled || $localIndexIsEnabled)
        {
            $item = $args['record'];
            $avantElasticsearchIndexBuilder = new AvantElasticsearchIndexBuilder();

            if ($sharedIndexIsEnabled)
            {
                $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfSharedIndex());
                if ($item->public)
                {
                    // Save or add this public item to the shared index.
                    $avantElasticsearchIndexBuilder->addItemToIndex($item);
                }
                else
                {
                    // Attempt to delete this non-public item from the shared index. It's an 'attempt' because we don't
                    // know if the item is in the shared index, but if it is, it needs to get deleted. This logic
                    // handles the case where the items was public, but the admin just unchecked the public box and
                    // just now saved the item. When they save an item that is already non-public, the delete will fail
                    // because the item is not in the shared index, but that's okay because we are telling the delete
                    // method to ignore the error that will result from trying to delete a non-existent item.
                    $okIfMissing = true;
                    $avantElasticsearchIndexBuilder->deleteItemFromIndex($item, $okIfMissing);
                }
            }

            if ($localIndexIsEnabled)
            {
                // Save or add the item to the local index. Both public and non-public items get saved/added.
                $avantElasticsearchIndexBuilder->setIndexName(AvantElasticsearch::getNameOfLocalIndex());
                $avantElasticsearchIndexBuilder->addItemToIndex($item);
            }
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
