<?php
/**
 * The Package Sync admin page — Blesta counterpart to the WHMCS addon's
 * brandforge_output() wizard/table (modules/addons/brandforge/brandforge.php).
 *
 * Unlike WHMCS's single-page POST dispatcher, every action here is its own
 * routable method (plugin controllers are directly routable — see
 * reference/plugins/create.md §5); each one performs its action, flashes a
 * message, and redirects back to index() so there's no resubmission risk.
 */
class AdminMain extends BrandforgeController
{
    private $settings;

    public function preAction()
    {
        parent::preAction();
        $this->requireLogin();

        $this->uses(['Brandforge.BrandforgeSettings', 'Brandforge.BrandforgePackageMap']);
        $this->helpers(['Form', 'Html', 'Widget']);
        Language::loadLang('admin_main', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);

        Loader::load(PLUGINDIR . 'brandforge' . DS . 'apis' . DS . 'brandforge_sync_exceptions.php');
        Loader::load(PLUGINDIR . 'brandforge' . DS . 'apis' . DS . 'brandforge_sync_client.php');

        $this->settings = $this->BrandforgeSettings->get($this->company_id);
    }

    public function index()
    {
        $mappings = $this->BrandforgePackageMap->getAll($this->company_id);

        $linked = 0;
        foreach ($mappings as $mapping) {
            if (!empty($mapping->blesta_package_id)) {
                $linked++;
            }
        }

        $this->set('settings', $this->settings);
        $this->set('has_creds', $this->settings->api_url !== '' && $this->settings->api_key !== '');
        $this->set('mappings', $mappings);
        $this->set('total', count($mappings));
        $this->set('linked', $linked);
        $this->set('pending', count($mappings) - $linked);
        $this->set('blesta_packages', $this->getBlestaPackages());
    }

    public function sync_all()
    {
        $client = $this->getClient();
        if (!$client) {
            return;
        }

        try {
            $result = $this->BrandforgePackageMap->syncAll($this->company_id, $client);
        } catch (Exception $e) {
            $this->flashMessage('error', Language::_('AdminMain.flash.sync_failed', true, $e->getMessage()));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return;
        }

        if (!empty($result['errors'])) {
            $this->flashMessage(
                'error',
                Language::_('AdminMain.flash.sync_partial', true, $result['synced'], implode('; ', $result['errors']))
            );
        } else {
            $this->flashMessage('message', Language::_('AdminMain.flash.sync_success', true, $result['synced']));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
    }

    public function auto_create_package()
    {
        $godmodePackageId = $this->get[0] ?? '';
        $mapping = $godmodePackageId !== ''
            ? $this->BrandforgePackageMap->findByGodmodeId($this->company_id, $godmodePackageId)
            : null;

        if (!$mapping) {
            $this->flashMessage('error', Language::_('AdminMain.flash.package_not_found', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return;
        }

        if (!empty($mapping->blesta_package_id)) {
            $this->flashMessage('error', Language::_('AdminMain.flash.already_linked', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return;
        }

        if (empty($this->settings->module_row_id)) {
            $this->flashMessage('error', Language::_('AdminMain.flash.no_module_row', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return;
        }

        $moduleId = $this->getBrandforgeModuleId();
        if (!$moduleId) {
            $this->flashMessage('error', Language::_('AdminMain.flash.module_not_installed', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return;
        }

        try {
            $packageId = $this->BrandforgePackageMap->createPackage(
                $this->company_id,
                $moduleId,
                (int) $this->settings->module_row_id,
                $mapping->godmode_name,
                $mapping->godmode_slug
            );
            $this->BrandforgePackageMap->setBlestaPackage($this->company_id, $godmodePackageId, $packageId);
            $this->flashMessage('message', Language::_('AdminMain.flash.package_created', true, $mapping->godmode_name));
        } catch (Exception $e) {
            $this->flashMessage('error', Language::_('AdminMain.flash.create_failed', true, $e->getMessage()));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
    }

    public function link_package()
    {
        $godmodePackageId = (string) ($this->post['godmode_package_id'] ?? '');
        $blestaPackageId = (int) ($this->post['blesta_package_id'] ?? 0);

        if ($godmodePackageId === '' || $blestaPackageId <= 0) {
            $this->flashMessage('error', Language::_('AdminMain.flash.select_package', true));
        } else {
            $this->BrandforgePackageMap->setBlestaPackage($this->company_id, $godmodePackageId, $blestaPackageId);
            $this->flashMessage('message', Language::_('AdminMain.flash.linked', true));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
    }

    public function unlink_package()
    {
        $godmodePackageId = (string) ($this->get[0] ?? '');
        if ($godmodePackageId !== '') {
            $this->BrandforgePackageMap->setBlestaPackage($this->company_id, $godmodePackageId, null);
            $this->flashMessage('message', Language::_('AdminMain.flash.unlinked', true));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
    }

    public function reset_all()
    {
        // Mapping table only — never touches brandforge_settings or any
        // already-created Package, same guarantee as the WHMCS addon's
        // reset_all (products preserved on reset).
        $this->BrandforgePackageMap->reset($this->company_id);
        $this->flashMessage('message', Language::_('AdminMain.flash.reset_complete', true));
        $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function getClient(): ?BrandforgeSyncClient
    {
        if ($this->settings->api_url === '' || $this->settings->api_key === '') {
            $this->flashMessage('error', Language::_('AdminMain.flash.no_credentials', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_main/');
            return null;
        }

        return new BrandforgeSyncClient($this->settings->api_url, $this->settings->api_key);
    }

    /** @return int|null The installed brandforge Module's id, or null if not found/not installed. */
    private function getBrandforgeModuleId(): ?int
    {
        try {
            Loader::loadModels($this, ['ModuleManager']);
            $modules = $this->ModuleManager->getAll($this->company_id);
            foreach ((array) $modules as $module) {
                if (strcasecmp((string) ($module->class ?? ''), 'brandforge') === 0) {
                    return (int) $module->id;
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    /** @return \stdClass[] Existing Blesta packages, for the "link existing package" dropdown. */
    private function getBlestaPackages(): array
    {
        try {
            Loader::loadModels($this, ['Packages']);
            $packages = $this->Packages->getAll($this->company_id);
            return (array) $packages;
        } catch (Exception $e) {
            return [];
        }
    }
}
