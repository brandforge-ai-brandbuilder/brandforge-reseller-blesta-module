<?php
/**
 * Godmode package <-> Blesta package mapping, and the sync/auto-create
 * logic that maintains it.
 *
 * Ports the WHMCS addon's PackageRepository + PackageSync + the
 * find-or-create guarantee from WhmcsProductManager
 * (brandforge-reseller-whmcs-module/modules/addons/brandforge/lib/) —
 * re-expressed against Blesta's own Packages/PackageGroups models instead
 * of WHMCS's tblproducts/tblproductgroups.
 *
 * UNVERIFIED RISK: findOrCreatePackageGroup()/findExistingPackageByName()/
 * createPackage() call Packages::add()/PackageGroups::add()/getAll() with
 * field shapes based on general Blesta model conventions, not confirmed
 * against app/models/packages.php on a live Blesta 6 install in this
 * environment (no Blesta instance is available here). sync/upsert/reset
 * only use this plugin's own table via $this->Record and are not at risk.
 * See the plugin README before relying on auto_create_package in
 * production.
 */
class BrandforgePackageMap extends AppModel
{
    private const GROUP_NAME = 'BrandForge';

    public function __construct()
    {
        parent::__construct();
        Language::loadLang('brandforge_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);
    }

    // -------------------------------------------------------------------
    // Mapping table CRUD
    // -------------------------------------------------------------------

    /** @return \stdClass[] */
    public function getAll($company_id): array
    {
        $rows = $this->Record->select()
            ->from('brandforge_package_map')
            ->where('company_id', '=', $company_id)
            ->order(['godmode_name' => 'ASC'])
            ->fetchAll();

        return $rows ?: [];
    }

    public function findByGodmodeId($company_id, string $godmodePackageId)
    {
        return $this->Record->select()
            ->from('brandforge_package_map')
            ->where('company_id', '=', $company_id)
            ->where('godmode_package_id', '=', $godmodePackageId)
            ->fetch();
    }

    /** Insert or update a mapping row, preserving an existing blesta_package_id link. */
    public function upsert($company_id, string $godmodePackageId, string $godmodeSlug, string $godmodeName): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByGodmodeId($company_id, $godmodePackageId);

        if ($existing) {
            $this->Record->where('id', '=', $existing->id)->update('brandforge_package_map', [
                'godmode_slug' => $godmodeSlug,
                'godmode_name' => $godmodeName,
                'updated_at' => $now,
            ]);
        } else {
            $this->Record->insert('brandforge_package_map', [
                'company_id' => $company_id,
                'godmode_package_id' => $godmodePackageId,
                'godmode_slug' => $godmodeSlug,
                'godmode_name' => $godmodeName,
                'blesta_package_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function setBlestaPackage($company_id, string $godmodePackageId, ?int $blestaPackageId): void
    {
        $this->Record->where('company_id', '=', $company_id)
            ->where('godmode_package_id', '=', $godmodePackageId)
            ->update('brandforge_package_map', [
                'blesta_package_id' => $blestaPackageId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** Drop every mapping row for this company (the "Reset" action) — never touches created Packages. */
    public function reset($company_id): void
    {
        $this->Record->from('brandforge_package_map')
            ->where('company_id', '=', $company_id)
            ->delete();
    }

    // -------------------------------------------------------------------
    // Sync — ports PackageSync::syncAll()
    // -------------------------------------------------------------------

    /**
     * Fetch packages from Godmode and upsert into the mapping table.
     * Tolerates {data:[...]}, {packages:[...]}, or a bare indexed array —
     * same response-shape tolerance as the WHMCS addon's PackageSync.
     *
     * @return array{synced:int, errors:string[]}
     */
    public function syncAll($company_id, BrandforgeSyncClient $client): array
    {
        $response = $client->getPackages();
        $packages = $this->extractPackageList($response);

        $result = ['synced' => 0, 'errors' => []];

        foreach ($packages as $pkg) {
            try {
                $id = (string) ($pkg['id'] ?? $pkg['package_id'] ?? $pkg['_id'] ?? '');
                $slug = (string) ($pkg['slug'] ?? $pkg['identifier'] ?? $pkg['key'] ?? '');
                $name = (string) ($pkg['name'] ?? $pkg['title'] ?? $pkg['display_name'] ?? $slug);

                if ($id === '' || $slug === '') {
                    throw new RuntimeException('Package is missing id or slug.');
                }

                $this->upsert($company_id, $id, $slug, $name);
                $result['synced']++;
            } catch (Exception $e) {
                $label = $pkg['name'] ?? $pkg['slug'] ?? $pkg['id'] ?? 'unknown';
                $result['errors'][] = "{$label}: " . $e->getMessage();
            }
        }

        return $result;
    }

    private function extractPackageList(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        if (isset($response['packages']) && is_array($response['packages'])) {
            return $response['packages'];
        }

        if (isset($response[0])) {
            return $response;
        }

        return [];
    }

    // -------------------------------------------------------------------
    // Auto-create — ports WhmcsProductManager's find-or-create guarantee.
    // See class docblock: unverified against a live Blesta install.
    // -------------------------------------------------------------------

    /**
     * Find-or-create the "BrandForge" package group.
     *
     * @throws RuntimeException
     */
    public function findOrCreatePackageGroup($company_id): int
    {
        Loader::loadModels($this, ['PackageGroups']);

        $groups = $this->PackageGroups->getAll($company_id);
        foreach ((array) $groups as $group) {
            if (strcasecmp((string) $group->name, self::GROUP_NAME) === 0) {
                return (int) $group->id;
            }
        }

        $groupId = $this->PackageGroups->add([
            'company_id' => $company_id,
            'name' => self::GROUP_NAME,
            'description' => 'BrandForge plans synced from Godmode.',
            'type' => 'standard',
        ]);

        $errors = $this->PackageGroups->errors();
        if (!empty($errors)) {
            throw new RuntimeException(
                'Could not create the "BrandForge" package group: ' . $this->flattenErrors($errors)
            );
        }

        return (int) $groupId;
    }

    /** Find an existing Blesta package by name within the given group. Returns null if none exists. */
    public function findExistingPackageByName($company_id, int $groupId, string $name): ?int
    {
        Loader::loadModels($this, ['Packages']);

        $packages = $this->Packages->getAll($company_id);
        foreach ((array) $packages as $package) {
            if (strcasecmp((string) $package->name, $name) !== 0) {
                continue;
            }

            foreach ((array) ($package->groups ?? []) as $group) {
                if ((int) ($group->id ?? 0) === $groupId) {
                    return (int) $package->id;
                }
            }
        }

        return null;
    }

    /**
     * Find-or-create a Package bound to the installed brandforge module,
     * inside the "BrandForge" package group, with meta[plan_code] set to
     * the Godmode plan's slug. Idempotent — matches
     * WhmcsProductManager::createProductFull's find-by-name-in-group
     * guarantee so re-running sync/setup never duplicates packages.
     *
     * Pricing defaults to $0/month — Blesta packages require pricing to
     * save; the admin sets the real price afterward (flagged in the UI).
     *
     * @throws RuntimeException
     */
    public function createPackage($company_id, int $moduleId, ?int $moduleRowId, string $name, string $planCode): int
    {
        $groupId = $this->findOrCreatePackageGroup($company_id);

        $existing = $this->findExistingPackageByName($company_id, $groupId, $name);
        if ($existing !== null) {
            return $existing;
        }

        Loader::loadModels($this, ['Packages']);

        $vars = [
            'company_id' => $company_id,
            'module_id' => $moduleId,
            'module_row' => $moduleRowId,
            'names' => [['lang' => 'en_us', 'name' => $name]],
            'descriptions' => [['lang' => 'en_us', 'html' => '', 'text' => '']],
            'email_content' => [['lang' => 'en_us', 'html' => '', 'text' => '']],
            'groups' => [$groupId],
            'status' => 'active',
            'taxable' => 0,
            'single_term' => 0,
            'pricing' => [
                [
                    'term' => 1,
                    'period' => 'month',
                    'price' => '0.0000',
                    'setup_fee' => '0.0000',
                    'cancel_fee' => '0.0000',
                    'currency' => 'USD',
                ],
            ],
            'meta' => ['plan_code' => $planCode],
        ];

        $packageId = $this->Packages->add($vars);

        $errors = $this->Packages->errors();
        if (!empty($errors)) {
            throw new RuntimeException(
                'Could not create Blesta package "' . $name . '": ' . $this->flattenErrors($errors)
            );
        }

        return (int) $packageId;
    }

    private function flattenErrors(array $errors): string
    {
        $flat = [];
        foreach ($errors as $fieldErrors) {
            foreach ((array) $fieldErrors as $message) {
                $flat[] = (string) $message;
            }
        }

        return $flat !== [] ? implode('; ', $flat) : 'unknown error';
    }
}
