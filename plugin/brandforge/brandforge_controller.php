<?php
/**
 * Shared base for every BrandForge plugin controller — sets the default
 * view path and loads the handler's language pack, same as every
 * admin_* controller here would otherwise repeat individually.
 */
class BrandforgeController extends AppController
{
    public function preAction()
    {
        parent::preAction();

        $this->view->setDefaultView('plugins' . DS . 'brandforge' . DS);
        Language::loadLang('brandforge_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);
    }
}
