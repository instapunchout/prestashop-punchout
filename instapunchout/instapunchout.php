<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Instapunchout extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'instapunchout';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'InstaPunchout';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('InstaPunchout');
        $this->description = $this->l('Punchout Support By InstaPunchout');

        $this->confirmUninstall = $this->l('Do you want to uninstall this module?');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('moduleRoutes');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader(array $params)
    {
        if (method_exists($this->context->controller, 'registerJavascript')) {
            $this->context->controller->registerJavascript(
                'punchout_header_js',
                '/module/instapunchout/punchout?action=script',
                array('server' => 'remote', 'position' => 'head', 'priority' => 140)
            );
        } else {
            $this->context->controller->addJS(
                '/module/instapunchout/punchout?action=script',
                false
            );
        }

    }

}