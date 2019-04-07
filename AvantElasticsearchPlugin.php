<?php

class AvantElasticsearchPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $item;

    protected $_hooks = array(
        'config',
        'config_form',
        'define_routes',
        'install'
    );

    protected $_filters = array(
        'admin_navigation_main'
    );

    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('Elasticsearch'),
            'uri' => url('elasticsearch/indexing')
        );
        return $nav;
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

    public function hookInstall()
    {
        ElasticsearchConfig::setDefaultOptionValues();
    }
}
