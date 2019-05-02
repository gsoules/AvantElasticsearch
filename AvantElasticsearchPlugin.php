<?php

define('AVANTELASTICSEARCH_PLUGIN_DIR', dirname(__FILE__));

class AvantElasticsearchPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $item;

    protected $_hooks = array(
        'admin_head',
        'config',
        'config_form',
        'define_routes',
        'public_footer',
        'public_head'
    );

    protected $_filters = array(
        'admin_navigation_main'
    );

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
        // Determine if the installed version of Avantsearch has support for AvantElasticsearch.
        $avantSearchVersionSupportsElasticsearch = method_exists('AvantSearch', 'useElasticsearch');

        if ($avantSearchVersionSupportsElasticsearch && AvantSearch::useElasticsearch())
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
