<?php
/**
 * The plugin's own settings — a debug flag, and which brandforge MODULE
 * row (Godmode account) Package Sync should talk to and bind newly-created
 * packages to.
 *
 * One row per company. No separate Godmode API URL/Key here on purpose:
 * Package Sync reuses whichever module row is selected, fetching its
 * credentials via ModuleManager::getRow() (see AdminPackageSync::getClient())
 * instead of asking the admin to re-enter them. The WHMCS addon keeps its
 * own separate godmode_api_url/godmode_api_key because a WHMCS install can
 * have several differently-configured server instances; in practice a
 * Blesta company has one Godmode account per module row, so storing the
 * same credentials twice was pure duplication, not real flexibility.
 */
class BrandforgeSettings extends AppModel
{
    public function __construct()
    {
        parent::__construct();
        Language::loadLang('brandforge_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);
    }

    public function get($company_id)
    {
        $row = $this->Record->select()
            ->from('brandforge_settings')
            ->where('company_id', '=', $company_id)
            ->fetch();

        if ($row) {
            return $row;
        }

        return (object) [
            'id' => null,
            'company_id' => $company_id,
            'module_row_id' => null,
            'default_brand_name' => '',
            'default_brand_color' => '',
            'default_brand_color_secondary' => '',
            'debug_mode' => 0,
        ];
    }

    /**
     * @return bool true on success; on failure call errors() for details
     */
    public function save($company_id, array $vars): bool
    {
        $this->Input->setRules($this->getRules());
        if (!$this->Input->validates($vars)) {
            return false;
        }

        $existing = $this->get($company_id);
        $now = date('Y-m-d H:i:s');

        $data = [
            'module_row_id' => !empty($vars['module_row_id']) ? (int) $vars['module_row_id'] : null,
            'default_brand_name' => trim((string) ($vars['default_brand_name'] ?? '')),
            'default_brand_color' => trim((string) ($vars['default_brand_color'] ?? '')),
            'default_brand_color_secondary' => trim((string) ($vars['default_brand_color_secondary'] ?? '')),
            'debug_mode' => !empty($vars['debug_mode']) ? 1 : 0,
            'date_updated' => $now,
        ];

        if (!empty($existing->id)) {
            $this->Record->where('id', '=', $existing->id)->update('brandforge_settings', $data);
        } else {
            $data['company_id'] = $company_id;
            $data['date_added'] = $now;
            $this->Record->insert('brandforge_settings', $data);
        }

        return true;
    }

    private function getRules(): array
    {
        return [
            'module_row_id' => [
                'valid' => [
                    'rule' => function ($value) {
                        return empty($value) || is_numeric($value);
                    },
                    'message' => Language::_('BrandforgePlugin.!error.settings.module_row_id.invalid', true)
                ]
            ]
        ];
    }
}
