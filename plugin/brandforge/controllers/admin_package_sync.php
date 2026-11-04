<?php
/**
 * The Package Sync admin page — Blesta counterpart to the WHMCS addon's
 * brandforge_output() wizard/table (modules/addons/brandforge/brandforge.php).
 *
 * Unlike WHMCS's single-page POST dispatcher, every action here is its own
 * routable method (plugin controllers are directly routable — see
 * reference/plugins/create.md §5); each one performs its action, flashes a
 * message, and redirects back to index() so there's no resubmission risk.
 *
 * NAMED "AdminPackageSync" (file admin_package_sync.php) — two separate,
 * confirmed-on-a-live-Blesta-6.0.2-install constraints shaped this name:
 *
 * 1. NOT "AdminMain" — a plugin controller class literally named `AdminMain`
 *    gets picked up by whatever mechanism Blesta uses to resolve the bare
 *    `/admin/` dashboard route, hijacking the ENTIRE admin area.
 * 2. MUST start with `admin_` — every stock plugin controller that works
 *    (order's admin_forms, billing_overview's admin_main) follows this
 *    prefix; a controller named `brandforge_admin_main` (no `admin_` prefix)
 *    reliably failed `isLoggedIn()` despite a fully valid, correctly
 *    populated staff session (verified by dumping raw session state) —
 *    `isLoggedIn()`/`requireLogin()` appear to use the controller name to
 *    decide staff-vs-client session context, and a non-`admin_`-prefixed
 *    plugin controller name reads as a non-admin (client) request, which
 *    naturally finds no client session and silently redirects to login.
 *
 * Keep this name unique, keep the `admin_` prefix, and do not rename it
 * back to `AdminMain`.
 */
class AdminPackageSync extends BrandforgeController
{
    private $settings;

    public function preAction()
    {
        parent::preAction();
        $this->requireLogin();

        $this->uses(['Brandforge.BrandforgeSettings', 'Brandforge.BrandforgePackageMap']);
        $this->helpers(['Form', 'Html', 'Widget']);
        Language::loadLang('admin_package_sync', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);

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
        $this->set('has_creds', !empty($this->settings->module_row_id) && $this->getModuleRow() !== null);
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
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.sync_failed', true, $e->getMessage()));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
            return;
        }

        if (!empty($result['errors'])) {
            $this->flashMessage(
                'error',
                Language::_('AdminPackageSync.flash.sync_partial', true, $result['synced'], implode('; ', $result['errors']))
            );
        } else {
            $this->flashMessage('message', Language::_('AdminPackageSync.flash.sync_success', true, $result['synced']));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
    }

    public function auto_create_package()
    {
        $godmodePackageId = $this->get[0] ?? '';
        $mapping = $godmodePackageId !== ''
            ? $this->BrandforgePackageMap->findByGodmodeId($this->company_id, $godmodePackageId)
            : null;

        if (!$mapping) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.package_not_found', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
            return;
        }

        if (!empty($mapping->blesta_package_id)) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.already_linked', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
            return;
        }

        if (empty($this->settings->module_row_id)) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.no_module_row', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
            return;
        }

        $moduleId = $this->getBrandforgeModuleId();
        if (!$moduleId) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.module_not_installed', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
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
            $this->flashMessage('message', Language::_('AdminPackageSync.flash.package_created', true, $mapping->godmode_name));
        } catch (Exception $e) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.create_failed', true, $e->getMessage()));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
    }

    public function link_package()
    {
        $godmodePackageId = (string) ($this->post['godmode_package_id'] ?? '');
        $blestaPackageId = (int) ($this->post['blesta_package_id'] ?? 0);

        if ($godmodePackageId === '' || $blestaPackageId <= 0) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.select_package', true));
        } else {
            $this->BrandforgePackageMap->setBlestaPackage($this->company_id, $godmodePackageId, $blestaPackageId);
            $this->flashMessage('message', Language::_('AdminPackageSync.flash.linked', true));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
    }

    public function unlink_package()
    {
        $godmodePackageId = (string) ($this->get[0] ?? '');
        if ($godmodePackageId !== '') {
            $this->BrandforgePackageMap->setBlestaPackage($this->company_id, $godmodePackageId, null);
            $this->flashMessage('message', Language::_('AdminPackageSync.flash.unlinked', true));
        }

        $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
    }

    public function reset_all()
    {
        // Mapping table only — never touches brandforge_settings or any
        // already-created Package, same guarantee as the WHMCS addon's
        // reset_all (products preserved on reset).
        $this->BrandforgePackageMap->reset($this->company_id);
        $this->flashMessage('message', Language::_('AdminPackageSync.flash.reset_complete', true));
        $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function getClient(): ?BrandforgeSyncClient
    {
        $row = $this->getModuleRow();
        if (!$row || empty($row->meta->api_url) || empty($row->meta->api_key)) {
            $this->flashMessage('error', Language::_('AdminPackageSync.flash.no_credentials', true));
            $this->redirect($this->base_uri . 'plugin/brandforge/admin_package_sync/');
            return null;
        }

        return new BrandforgeSyncClient($row->meta->api_url, $row->meta->api_key);
    }

    /**
     * The brandforge module row selected on the plugin's settings page —
     * the single source of truth for Godmode credentials (see
     * BrandforgeSettings for why Package Sync has no API URL/Key of its own).
     */
    private function getModuleRow(): ?\stdClass
    {
        if (empty($this->settings->module_row_id)) {
            return null;
        }

        try {
            Loader::loadModels($this, ['ModuleManager']);
            $row = $this->ModuleManager->getRow((int) $this->settings->module_row_id);

            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
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
