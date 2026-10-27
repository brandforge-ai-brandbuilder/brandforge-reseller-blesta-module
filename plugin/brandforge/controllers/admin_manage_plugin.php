<?php
/**
 * The "Manage" settings page — Settings > Company > Plugins > Manage.
 *
 * Embedded (not routed) by AdminCompanyPlugins::manage(); per
 * reference/plugins/create.md §9 this is constructed directly and exposes
 * $this->parent (the AdminCompanyPlugins instance) instead of running a
 * normal preAction(). Its action must RETURN rendered HTML.
 */
class AdminManagePlugin extends AppController
{
    private function init(): void
    {
        $this->parent->requireLogin();
        Language::loadLang('admin_manage_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);
        Language::loadLang('brandforge_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);

        $this->uses(['Brandforge.BrandforgeSettings']);

        // The embedding plugin's id (not this plugin's own settings row id) —
        // the Manage URL and this form's action both key off it.
        $this->plugin_id = $this->get[0] ?? null;

        $this->parent->structure->set('page_title', Language::_('AdminManagePlugin.page_title', true));
    }

    public function index()
    {
        $this->init();

        $companyId = Configure::get('Blesta.company_id');
        $vars = $this->BrandforgeSettings->get($companyId);
        $errors = [];

        if (!empty($this->post)) {
            if ($this->BrandforgeSettings->save($companyId, $this->post)) {
                $this->parent->flashMessage('message', Language::_('AdminManagePlugin.flash.saved', true));
                $vars = $this->BrandforgeSettings->get($companyId);
            } else {
                $errors = $this->BrandforgeSettings->errors();
                $vars = (object) array_merge((array) $vars, $this->post);
            }
        }

        Loader::loadHelpers($this, ['Html', 'Form']);
        $this->view->setView(null, 'Brandforge.default');

        return $this->partial('admin_manage_plugin', [
            'vars' => $vars,
            'errors' => $errors,
            'plugin_id' => $this->plugin_id,
        ]);
    }
}
