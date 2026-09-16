<?php
/**
 * BrandForge provisioning module for Blesta.
 *
 * Connects a Blesta service's lifecycle (add/suspend/unsuspend/cancel/change
 * package) to the Godmode API, mirroring the WHMCS module's behaviour
 * (brandforge-reseller-whmcs-module/modules/servers/brandforge/brandforge.php)
 * re-expressed against Blesta's Module contract.
 *
 * Stacking rule (ported from the WHMCS module): a client's first package
 * creates a Godmode account (provision/create); every package after that
 * attaches to the SAME account via provision/add_plan. Blesta's addService()
 * has no service id yet at call time (the service row doesn't exist until
 * after this returns), so — unlike the WHMCS module, which tracked this via
 * a table keyed by its own service id — the local `brandforge_accounts`
 * table here is keyed by client_id/godmode_service_id and just counts how
 * many active plans reference each Godmode account. See findOpenAccount()/
 * decrementAccountPlanCount() below.
 */
class Brandforge extends Module
{
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input', 'Record']);
        Language::loadLang('brandforge', null, dirname(__FILE__) . DS . 'language' . DS);

        $apiDir = dirname(__FILE__) . DS . 'apis' . DS;
        Loader::load($apiDir . 'brandforge_exceptions.php');
        Loader::load($apiDir . 'brandforge_client.php');
        Loader::load($apiDir . 'brandforge_mapper.php');
        Loader::load($apiDir . 'brandforge_sso.php');
    }

    // -------------------------------------------------------------------
    // Install / uninstall
    // -------------------------------------------------------------------

    public function install()
    {
        $this->Record->query(
            'CREATE TABLE IF NOT EXISTS `brandforge_accounts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id` INT UNSIGNED NOT NULL,
                `godmode_service_id` VARCHAR(255) NOT NULL,
                `godmode_workspace_id` VARCHAR(255) DEFAULT NULL,
                `godmode_user_id` VARCHAR(255) DEFAULT NULL,
                `plan_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `closed_at` DATETIME DEFAULT NULL,
                `date_added` DATETIME NOT NULL,
                `date_updated` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `godmode_service_id` (`godmode_service_id`),
                KEY `client_id` (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
        );
    }

    public function uninstall($module_id, $last_instance)
    {
        if ($last_instance) {
            $this->Record->query('DROP TABLE IF EXISTS `brandforge_accounts`');
        }
    }

    // -------------------------------------------------------------------
    // Module rows (Godmode API connections)
    // -------------------------------------------------------------------

    public function manageModule($module, array &$vars)
    {
        return '<p>' . Language::_('Brandforge.manage.description', true) . '</p>';
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'brandforge' . DS);
        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        }

        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'brandforge' . DS);
        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules($this->getRowRules());
        if (!$this->Input->validates($vars)) {
            return;
        }

        $this->testRowConnection($vars);
        if ($this->Input->errors()) {
            return;
        }

        return $this->buildRowMeta($vars);
    }

    public function editModuleRow($module_row, array &$vars)
    {
        return $this->addModuleRow($vars);
    }

    public function deleteModuleRow($module_row)
    {
        return true;
    }

    private function getRowRules(): array
    {
        return [
            'api_url' => [
                'empty' => [
                    'rule' => function ($value) {
                        return trim((string) $value) !== '';
                    },
                    'message' => Language::_('Brandforge.!error.row.api_url.empty', true)
                ],
                'format' => [
                    'rule' => function ($value) {
                        return (bool) preg_match('#^https?://#i', trim((string) $value));
                    },
                    'message' => Language::_('Brandforge.!error.row.api_url.invalid', true)
                ]
            ],
            'api_key' => [
                'empty' => [
                    'rule' => function ($value) {
                        return trim((string) $value) !== '';
                    },
                    'message' => Language::_('Brandforge.!error.row.api_key.empty', true)
                ]
            ]
        ];
    }

    private function testRowConnection(array $vars): void
    {
        $apiUrl = rtrim(trim((string) ($vars['api_url'] ?? '')), '/');
        $apiKey = trim((string) ($vars['api_key'] ?? ''));

        try {
            (new BrandforgeClient($apiUrl, $apiKey))->testConnection();
        } catch (BrandforgeApiException $e) {
            $this->Input->setErrors([
                'api_url' => [
                    'test_connection' => Language::_('Brandforge.!error.row.test_connection', true, $e->getMessage())
                ]
            ]);
        }
    }

    private function buildRowMeta(array $vars): array
    {
        return [
            ['key' => 'api_url', 'value' => rtrim(trim((string) $vars['api_url']), '/'), 'encrypted' => 0],
            ['key' => 'api_key', 'value' => trim((string) $vars['api_key']), 'encrypted' => 1],
        ];
    }

    private function getApi($module_row): BrandforgeClient
    {
        return new BrandforgeClient($module_row->meta->api_url, $module_row->meta->api_key);
    }

    // -------------------------------------------------------------------
    // Packages
    // -------------------------------------------------------------------

    public function getPackageFields($vars = null)
    {
        $fields = new InputFields();

        $planCode = $fields->label(Language::_('Brandforge.package_meta.plan_code', true), 'brandforge_plan_code');
        $planCode->attach($fields->fieldText(
            'meta[plan_code]',
            $this->packageMetaValue($vars, 'plan_code'),
            ['id' => 'brandforge_plan_code', 'class' => 'form-control']
        ));
        $planCode->attach($fields->tooltip(Language::_('Brandforge.package_meta.plan_code.tooltip', true)));
        $fields->setField($planCode);

        $brandName = $fields->label(Language::_('Brandforge.package_meta.brand_name', true), 'brandforge_brand_name');
        $brandName->attach($fields->fieldText(
            'meta[brand_name]',
            $this->packageMetaValue($vars, 'brand_name'),
            ['id' => 'brandforge_brand_name', 'class' => 'form-control']
        ));
        $fields->setField($brandName);

        $brandColor = $fields->label(
            Language::_('Brandforge.package_meta.brand_color', true),
            'brandforge_brand_color'
        );
        $brandColor->attach($fields->fieldText(
            'meta[brand_color]',
            $this->packageMetaValue($vars, 'brand_color', '#6366f1'),
            ['id' => 'brandforge_brand_color', 'class' => 'form-control']
        ));
        $fields->setField($brandColor);

        $brandColorSecondary = $fields->label(
            Language::_('Brandforge.package_meta.brand_color_secondary', true),
            'brandforge_brand_color_secondary'
        );
        $brandColorSecondary->attach($fields->fieldText(
            'meta[brand_color_secondary]',
            $this->packageMetaValue($vars, 'brand_color_secondary'),
            ['id' => 'brandforge_brand_color_secondary', 'class' => 'form-control']
        ));
        $fields->setField($brandColorSecondary);

        $frontendUrl = $fields->label(
            Language::_('Brandforge.package_meta.frontend_url', true),
            'brandforge_frontend_url'
        );
        $frontendUrl->attach($fields->fieldText(
            'meta[frontend_url]',
            $this->packageMetaValue($vars, 'frontend_url'),
            ['id' => 'brandforge_frontend_url', 'class' => 'form-control']
        ));
        $fields->setField($frontendUrl);

        $language = $fields->label(
            Language::_('Brandforge.package_meta.client_area_language', true),
            'brandforge_client_area_language'
        );
        $language->attach($fields->fieldSelect(
            'meta[client_area_language]',
            $this->clientAreaLanguages(),
            $this->packageMetaValue($vars, 'client_area_language', 'english'),
            ['id' => 'brandforge_client_area_language', 'class' => 'form-select']
        ));
        $fields->setField($language);

        return $fields;
    }

    public function addPackage(array $vars = null)
    {
        $this->Input->setRules($this->getPackageRules());
        if (!$this->Input->validates($vars)) {
            return;
        }

        return $this->buildPackageMeta($vars);
    }

    public function editPackage($package, array $vars = null)
    {
        return $this->addPackage($vars);
    }

    public function deletePackage($package)
    {
        return true;
    }

    private function getPackageRules(): array
    {
        return [
            'meta[plan_code]' => [
                'empty' => [
                    'rule' => function ($value) {
                        return trim((string) $value) !== '';
                    },
                    'message' => Language::_('Brandforge.!error.plan_code.missing', true)
                ]
            ]
        ];
    }

    private function buildPackageMeta(array $vars): array
    {
        $keys = ['plan_code', 'brand_name', 'brand_color', 'brand_color_secondary', 'frontend_url', 'client_area_language'];

        $meta = [];
        foreach ($keys as $key) {
            $meta[] = [
                'key' => $key,
                'value' => (string) ($vars['meta'][$key] ?? ''),
                'encrypted' => 0
            ];
        }

        return $meta;
    }

    private function packageMetaValue($vars, string $key, string $default = ''): string
    {
        if (is_object($vars) && isset($vars->meta[$key])) {
            return (string) $vars->meta[$key];
        }

        if (is_array($vars) && isset($vars['meta'][$key])) {
            return (string) $vars['meta'][$key];
        }

        return $default;
    }

    private function clientAreaLanguages(): array
    {
        // Mirrors the WHMCS module's Client Area Language dropdown
        // (modules/servers/brandforge/lib/Translator.php) — the Blesta
        // client-side translation strings themselves are not ported yet
        // (that's the client service tab / views work, not this module
        // skeleton), so this only records the admin's choice for now.
        return [
            'english' => 'English', 'french' => 'French', 'german' => 'German',
            'spanish' => 'Spanish', 'italian' => 'Italian', 'dutch' => 'Dutch',
            'portuguese-pt' => 'Portuguese (PT)', 'portuguese-br' => 'Portuguese (BR)',
            'russian' => 'Russian', 'czech' => 'Czech', 'hungarian' => 'Hungarian',
            'romanian' => 'Romanian', 'danish' => 'Danish', 'swedish' => 'Swedish',
            'norwegian' => 'Norwegian', 'croatian' => 'Croatian', 'estonian' => 'Estonian',
            'macedonian' => 'Macedonian', 'catalan' => 'Catalan', 'ukranian' => 'Ukrainian',
            'arabic' => 'Arabic', 'azerbaijani' => 'Azerbaijani', 'chinese' => 'Chinese',
            'farsi' => 'Farsi', 'hebrew' => 'Hebrew', 'turkish' => 'Turkish',
        ];
    }

    // -------------------------------------------------------------------
    // Service order form (no extra fields — account owner + plan are
    // enough, same as the WHMCS module never defining custom fields)
    // -------------------------------------------------------------------

    public function getAdminAddFields($package, $vars = null)
    {
        return new InputFields();
    }

    public function getClientAddFields($package, $vars = null)
    {
        return new InputFields();
    }

    // -------------------------------------------------------------------
    // Service lifecycle
    // -------------------------------------------------------------------

    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules([
            'client_id' => [
                'valid' => [
                    'rule' => 'is_numeric',
                    'message' => Language::_('Brandforge.!error.client_id.invalid', true)
                ]
            ]
        ]);

        return $this->Input->validates($vars);
    }

    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors([
                'module_row' => ['missing' => Language::_('Brandforge.!error.module_row.missing', true)]
            ]);
            return;
        }

        $planCode = $package->meta->plan_code ?? '';
        if ($planCode === '') {
            $this->Input->setErrors([
                'plan_code' => ['missing' => Language::_('Brandforge.!error.plan_code.missing', true)]
            ]);
            return;
        }

        if (!$this->validateService($package, $vars)) {
            return;
        }

        // 'false' means the admin is recording an account that already
        // exists on Godmode's side by hand — skip the remote call.
        if (($vars['use_module'] ?? 'true') !== 'true') {
            return [
                ['key' => 'godmode_service_id', 'value' => '', 'encrypted' => 0],
                ['key' => 'godmode_workspace_id', 'value' => '', 'encrypted' => 0],
                ['key' => 'godmode_user_id', 'value' => '', 'encrypted' => 0],
            ];
        }

        $clientId = (int) ($vars['client_id'] ?? 0);
        Loader::loadModels($this, ['Clients']);
        $client = $this->Clients->get($clientId);

        $api = $this->getApi($row);

        // BrandForge stacking rule (see class docblock): attach to the
        // client's existing open account instead of creating a second one.
        $account = $this->findOpenAccount($clientId);

        if ($account !== null) {
            return $this->attachPlanToAccount($api, $row, $account, $planCode);
        }

        return $this->createAccount($api, $row, $client, $clientId, $planCode);
    }

    private function createAccount(BrandforgeClient $api, $row, $client, int $clientId, string $planCode)
    {
        $payload = BrandforgeMapper::createAccountPayload([
            'email' => $client->email ?? '',
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'company_name' => $client->company ?? '',
        ], $planCode);

        $response = $this->callApi($row, 'create', function () use ($api, $payload) {
            return $api->createAccount($payload);
        }, $payload);

        if ($response === null) {
            return;
        }

        $data = $response['data'] ?? $response;
        $godmodeServiceId = (string) ($data['service_id'] ?? '');
        $workspaceId = (string) ($data['workspace_id'] ?? '');
        $userId = (string) ($data['user_id'] ?? '');

        if ($godmodeServiceId === '') {
            $this->Input->setErrors([
                'api' => ['create' => Language::_('Brandforge.!error.api.no_service_id', true)]
            ]);
            return;
        }

        $this->insertAccount($clientId, $godmodeServiceId, $workspaceId, $userId);

        return [
            ['key' => 'godmode_service_id', 'value' => $godmodeServiceId, 'encrypted' => 0],
            ['key' => 'godmode_workspace_id', 'value' => $workspaceId, 'encrypted' => 0],
            ['key' => 'godmode_user_id', 'value' => $userId, 'encrypted' => 0],
        ];
    }

    private function attachPlanToAccount(BrandforgeClient $api, $row, $account, string $planCode)
    {
        $payload = BrandforgeMapper::addPlanPayload($account->godmode_service_id, $planCode);

        $response = $this->callApi($row, 'add_plan', function () use ($api, $payload) {
            return $api->addPlan($payload);
        }, $payload);

        if ($response === null) {
            return;
        }

        $this->incrementAccountPlanCount((int) $account->id);

        return [
            ['key' => 'godmode_service_id', 'value' => $account->godmode_service_id, 'encrypted' => 0],
            ['key' => 'godmode_workspace_id', 'value' => (string) $account->godmode_workspace_id, 'encrypted' => 0],
            ['key' => 'godmode_user_id', 'value' => (string) $account->godmode_user_id, 'encrypted' => 0],
        ];
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->planAction($package, $service, 'suspend_plan', function (BrandforgeClient $api, array $payload) {
            return $api->suspendPlan($payload);
        });

        return null;
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->planAction($package, $service, 'unsuspend_plan', function (BrandforgeClient $api, array $payload) {
            return $api->unsuspendPlan($payload);
        });

        return null;
    }

    private function planAction($package, $service, string $label, callable $apiCall): void
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return;
        }

        $fields = $this->serviceFieldsToObject($service->fields);
        $godmodeServiceId = $fields->godmode_service_id ?? '';
        $planCode = $package->meta->plan_code ?? '';

        if ($godmodeServiceId === '' || $planCode === '') {
            return;
        }

        $api = $this->getApi($row);
        $payload = BrandforgeMapper::planPayload($godmodeServiceId, $planCode);

        $this->callApi($row, $label, function () use ($apiCall, $api, $payload) {
            return $apiCall($api, $payload);
        }, $payload);
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return null;
        }

        $fields = $this->serviceFieldsToObject($service->fields);
        $godmodeServiceId = $fields->godmode_service_id ?? '';
        $planCode = $package->meta->plan_code ?? '';

        if ($godmodeServiceId === '' || $planCode === '') {
            return null;
        }

        $api = $this->getApi($row);
        $payload = BrandforgeMapper::planPayload($godmodeServiceId, $planCode);

        $response = $this->callApi($row, 'terminate_plan', function () use ($api, $payload) {
            return $api->terminatePlan($payload);
        }, $payload);

        if ($response === null) {
            // callApi already set Input errors — don't touch local
            // bookkeeping for a plan Godmode never actually terminated.
            return null;
        }

        $account = $this->decrementAccountPlanCount($godmodeServiceId);

        // Belt-and-suspenders, mirrors the WHMCS module: if that was this
        // account's last active plan, also confirm the whole account
        // closes. Deliberately best-effort — the plan itself is already
        // terminated above, so a failure here must not surface as a
        // failed cancellation.
        if ($account !== null && (int) $account->plan_count <= 0) {
            $closePayload = BrandforgeMapper::servicePayload($godmodeServiceId);
            try {
                $api->terminateAccount($closePayload);
                $this->log($row->meta->api_url . '|terminate_account', serialize($closePayload), 'output', true);
            } catch (BrandforgeApiException $e) {
                $this->log(
                    $row->meta->api_url . '|terminate_account',
                    serialize(['error' => $e->getMessage()]),
                    'output',
                    false
                );
            }

            $this->closeAccount($godmodeServiceId);
        }

        return null;
    }

    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return null;
        }

        $newPlanCode = $package_to->meta->plan_code ?? '';
        if ($newPlanCode === '') {
            $this->Input->setErrors([
                'plan_code' => ['missing' => Language::_('Brandforge.!error.plan_code.missing', true)]
            ]);
            return;
        }

        $fields = $this->serviceFieldsToObject($service->fields);
        $godmodeServiceId = $fields->godmode_service_id ?? '';
        if ($godmodeServiceId === '') {
            return null;
        }

        $oldPlanCode = $package_from->meta->plan_code ?? '';
        if ($newPlanCode === $oldPlanCode) {
            // e.g. monthly vs. annual billing of the same tier mapped to
            // the same Godmode plan — nothing to swap remotely.
            return null;
        }

        $api = $this->getApi($row);

        // Grant the new plan BEFORE removing the old one — a failure here
        // leaves the customer with what they already had, never nothing.
        $addPayload = BrandforgeMapper::addPlanPayload($godmodeServiceId, $newPlanCode);
        $added = $this->callApi($row, 'add_plan', function () use ($api, $addPayload) {
            return $api->addPlan($addPayload);
        }, $addPayload);

        if ($added === null) {
            return null;
        }

        // Only remove the old plan once the new one is confirmed active.
        if ($oldPlanCode !== '') {
            $removePayload = BrandforgeMapper::planPayload($godmodeServiceId, $oldPlanCode);
            $this->callApi($row, 'terminate_plan', function () use ($api, $removePayload) {
                return $api->terminatePlan($removePayload);
            }, $removePayload);
        }

        return null;
    }

    // -------------------------------------------------------------------
    // Service display
    // -------------------------------------------------------------------

    public function getAdminServiceInfo($service, $package)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $serviceId = $fields->godmode_service_id ?? '';

        if ($serviceId === '') {
            return '';
        }

        return '<p>' . Language::_('Brandforge.service_info.service_id', true) . ': '
            . htmlspecialchars($serviceId, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->getAdminServiceInfo($service, $package);
    }

    // -------------------------------------------------------------------
    // Service tabs — SSO launch + usage dashboard (credits, workspaces).
    // Ported from the WHMCS module's brandforge_ClientArea() /
    // brandforge_LaunchBrandForge() / brandforge_ViewWorkspace(), which
    // combined SSO-URL generation and live usage stats into one page.
    //
    // NOTE — module admin tabs may ONLY be plain strings (no 'icon' key);
    // a ['name'=>,'icon'=>] array here renders as the literal text "Array"
    // (reference/common/conversion-ui.md §5.2). Client tabs DO support the
    // array form, and keep FontAwesome ('fas fa-…') for the 6.0 client
    // portal — do NOT convert client tab icons to Bootstrap Icons.
    // -------------------------------------------------------------------

    public function getAdminServiceTabs($service)
    {
        return [
            'tabAdminDashboard' => Language::_('Brandforge.tab.dashboard', true),
        ];
    }

    public function getClientServiceTabs($service)
    {
        return [
            'tabClientDashboard' => [
                'name' => Language::_('Brandforge.tab.dashboard', true),
                'icon' => 'fas fa-rocket',
            ],
        ];
    }

    public function tabAdminDashboard($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return '';
        }

        $this->view = new View('tab_admin_dashboard', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'brandforge' . DS);
        Loader::loadHelpers($this->view, ['Html']);
        foreach ($this->getDashboardData($row, $package, $service) as $key => $value) {
            $this->view->set($key, $value);
        }

        return $this->view->fetch();
    }

    public function tabClientDashboard($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $row = $this->getModuleRow();
        if (!$row) {
            return '';
        }

        $this->view = new View('tab_client_dashboard', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'brandforge' . DS);
        Loader::loadHelpers($this->view, ['Html']);
        foreach ($this->getDashboardData($row, $package, $service) as $key => $value) {
            $this->view->set($key, $value);
        }

        return $this->view->fetch();
    }

    /**
     * Shared by both dashboard tabs: the SSO login URL plus live credits/
     * workspaces usage from Godmode. Degrades gracefully — a failure in
     * either fetch still renders the rest of the tab rather than erroring
     * the whole page, same as the WHMCS module's clientarea template.
     */
    private function getDashboardData($row, $package, $service): array
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $godmodeServiceId = (string) ($fields->godmode_service_id ?? '');

        $data = [
            'has_service' => $godmodeServiceId !== '',
            'service_id' => $godmodeServiceId,
            'sso_url' => '',
            'sso_error' => '',
            'workspace_sso_url' => '',
            'workspace_sso_error' => '',
            'has_usage_data' => false,
            'credits_allocated' => 0,
            'credits_used' => 0,
            'credits_remaining' => 0,
            'credits_period_end' => '',
            'credits_over_limit' => false,
            'workspaces_max' => 0,
            'workspaces_active' => 0,
            'workspaces' => [],
            'brand_name' => $package->meta->brand_name ?? 'BrandForge',
            'brand_color' => $package->meta->brand_color ?? '#6366f1',
            'brand_color_secondary' => $package->meta->brand_color_secondary ?? '',
        ];

        if (!$data['has_service']) {
            return $data;
        }

        $api = $this->getApi($row);
        $frontendUrl = (string) ($package->meta->frontend_url ?? '');

        $sso = new BrandforgeSso($api);

        // Two separate one-time login URLs, mirroring the WHMCS module's
        // "Launch" and "View Workspace" custom buttons — each is a fresh
        // SSO token pointed at a different landing path.
        try {
            $data['sso_url'] = $sso->getLoginUrl($godmodeServiceId, '', $frontendUrl);
        } catch (Exception $e) {
            $data['sso_error'] = $e->getMessage();
        }

        try {
            $data['workspace_sso_url'] = $sso->getLoginUrl($godmodeServiceId, '/workspace', $frontendUrl);
        } catch (Exception $e) {
            $data['workspace_sso_error'] = $e->getMessage();
        }

        try {
            $raw = $api->getServiceInfo($godmodeServiceId);
            $info = $raw['data'] ?? $raw;
            $credits = $info['credits'] ?? [];
            $workspacesData = $info['workspaces'] ?? [];

            $allocated = (int) ($credits['allocated'] ?? 0);
            $used = (int) ($credits['used'] ?? 0);
            $list = $workspacesData['list'] ?? [];

            $data['has_usage_data'] = true;
            $data['credits_allocated'] = $allocated;
            $data['credits_used'] = $used;
            $data['credits_remaining'] = (int) ($credits['remaining'] ?? max(0, $allocated - $used));
            $data['credits_period_end'] = (string) ($credits['period_end'] ?? '');
            $data['credits_over_limit'] = !empty($credits['over_limit']);
            $data['workspaces_max'] = (int) (($info['package'] ?? [])['max_workspaces'] ?? 0);
            $data['workspaces'] = $list;
            $data['workspaces_active'] = (int) ($workspacesData['active'] ?: count($list));
        } catch (Exception $e) {
            // Silently degrade — the dashboard still shows the launch button.
        }

        return $data;
    }

    // -------------------------------------------------------------------
    // Remote call helper (logging + error translation, shared by every
    // lifecycle method above)
    // -------------------------------------------------------------------

    private function callApi($module_row, string $label, callable $call, array $requestPayload = [])
    {
        $logLabel = $module_row->meta->api_url . '|' . $label;
        $this->log($logLabel, serialize(BrandforgeClient::maskPayload($requestPayload)), 'input', true);

        try {
            $response = $call();
        } catch (BrandforgeApiException $e) {
            $this->log($logLabel, serialize(['error' => $e->getMessage()]), 'output', false);
            $this->Input->setErrors([
                'api' => [$label => Language::_('Brandforge.!error.api.general', true, $e->getMessage())]
            ]);
            return null;
        }

        $this->log($logLabel, serialize($response), 'output', true);
        return $response;
    }

    // -------------------------------------------------------------------
    // brandforge_accounts — local account/plan-count bookkeeping
    // (see class docblock for why this is keyed the way it is)
    // -------------------------------------------------------------------

    private function findOpenAccount(int $clientId)
    {
        return $this->Record->select()
            ->from('brandforge_accounts')
            ->where('client_id', '=', $clientId)
            ->where('closed_at', '=', null)
            ->order(['date_added' => 'ASC'])
            ->fetch();
    }

    private function insertAccount(int $clientId, string $godmodeServiceId, string $workspaceId, string $userId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->Record->insert('brandforge_accounts', [
            'client_id' => $clientId,
            'godmode_service_id' => $godmodeServiceId,
            'godmode_workspace_id' => $workspaceId !== '' ? $workspaceId : null,
            'godmode_user_id' => $userId !== '' ? $userId : null,
            'plan_count' => 1,
            'date_added' => $now,
            'date_updated' => $now,
        ]);
    }

    private function incrementAccountPlanCount(int $accountId): void
    {
        $account = $this->Record->select()
            ->from('brandforge_accounts')
            ->where('id', '=', $accountId)
            ->fetch();

        if (!$account) {
            return;
        }

        $this->Record->where('id', '=', $accountId)->update('brandforge_accounts', [
            'plan_count' => ((int) $account->plan_count) + 1,
            'date_updated' => date('Y-m-d H:i:s'),
        ]);
    }

    private function decrementAccountPlanCount(string $godmodeServiceId)
    {
        $account = $this->Record->select()
            ->from('brandforge_accounts')
            ->where('godmode_service_id', '=', $godmodeServiceId)
            ->fetch();

        if (!$account) {
            return null;
        }

        $newCount = max(0, ((int) $account->plan_count) - 1);

        $this->Record->where('id', '=', $account->id)->update('brandforge_accounts', [
            'plan_count' => $newCount,
            'date_updated' => date('Y-m-d H:i:s'),
        ]);

        $account->plan_count = $newCount;
        return $account;
    }

    private function closeAccount(string $godmodeServiceId): void
    {
        $this->Record->where('godmode_service_id', '=', $godmodeServiceId)->update('brandforge_accounts', [
            'closed_at' => date('Y-m-d H:i:s'),
            'date_updated' => date('Y-m-d H:i:s'),
        ]);
    }
}
