<?php
/**
 * The plugin's own settings — Godmode API URL/Key, debug flag, and which
 * brandforge MODULE row newly-created packages should bind to.
 *
 * One row per company. Deliberately separate storage from the brandforge
 * Module's own row credentials (module/brandforge/brandforge.php) — mirrors
 * the WHMCS addon, whose godmode_api_url/godmode_api_key are also stored
 * independently of the server module's.
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
            'api_url' => '',
            'api_key' => '',
            'module_row_id' => null,
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
            'api_url' => rtrim(trim((string) ($vars['api_url'] ?? '')), '/'),
            'api_key' => trim((string) ($vars['api_key'] ?? '')),
            'module_row_id' => !empty($vars['module_row_id']) ? (int) $vars['module_row_id'] : null,
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
            'api_url' => [
                'format' => [
                    'rule' => function ($value) {
                        $value = trim((string) $value);
                        return $value === '' || (bool) preg_match('#^https?://#i', $value);
                    },
                    'message' => Language::_('BrandforgePlugin.!error.settings.api_url.invalid', true)
                ]
            ],
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
