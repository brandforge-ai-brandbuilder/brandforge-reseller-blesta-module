<?php
/**
 * BrandForge Package Sync — Blesta plugin.
 *
 * The Blesta counterpart to the WHMCS addon module
 * (brandforge-reseller-whmcs-module/modules/addons/brandforge/brandforge.php):
 * an admin-only page that pulls Godmode's package catalog, maintains a local
 * mapping table, and auto-creates/links Blesta Packages so a reseller
 * doesn't have to hand-configure every package.
 *
 * Deliberately re-derived against Blesta's own plugin contract rather than
 * transliterating WHMCS's procedural addon — see
 * controllers/admin_main.php and models/brandforge_package_map.php for the
 * actual sync/create logic. This handler is just the declarations Blesta's
 * PluginManager reads on install/upgrade.
 */
class BrandforgePlugin extends Plugin
{
    public function __construct()
    {
        Language::loadLang('brandforge_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
    }

    // -------------------------------------------------------------------
    // Install / uninstall
    // -------------------------------------------------------------------

    public function install($plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        try {
            // One settings row per company — API URL/Key/debug flag and the
            // module row created packages bind to. Kept separate from the
            // brandforge MODULE's own row credentials, mirroring the WHMCS
            // addon's separately-stored godmode_api_url/godmode_api_key.
            $this->Record->query(
                'CREATE TABLE IF NOT EXISTS `brandforge_settings` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `company_id` INT UNSIGNED NOT NULL,
                    `api_url` VARCHAR(255) NOT NULL DEFAULT \'\',
                    `api_key` VARCHAR(255) NOT NULL DEFAULT \'\',
                    `module_row_id` INT UNSIGNED DEFAULT NULL,
                    `debug_mode` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                    `date_added` DATETIME NOT NULL,
                    `date_updated` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `company_id` (`company_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
            );

            // Godmode package <-> Blesta package mapping. Kept on uninstall
            // unless this is the last instance, same as the WHMCS addon
            // deliberately keeping mod_brandforge_packages on deactivation.
            $this->Record->query(
                'CREATE TABLE IF NOT EXISTS `brandforge_package_map` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `company_id` INT UNSIGNED NOT NULL,
                    `godmode_package_id` VARCHAR(255) NOT NULL,
                    `godmode_slug` VARCHAR(255) NOT NULL,
                    `godmode_name` VARCHAR(255) NOT NULL,
                    `blesta_package_id` INT UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `company_package` (`company_id`, `godmode_package_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
            );
        } catch (Exception $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
        }
    }

    public function uninstall($plugin_id, $last_instance)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        if ($last_instance) {
            $this->Record->query('DROP TABLE IF EXISTS `brandforge_package_map`');
            $this->Record->query('DROP TABLE IF EXISTS `brandforge_settings`');
        }
    }

    // -------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------

    public function getActions()
    {
        return [
            [
                'location' => 'nav_staff',
                'url' => 'plugin/brandforge/admin_main/',
                'name' => 'BrandforgePlugin.nav.main',
                'icon' => 'bi bi-arrow-left-right'
            ]
        ];
    }

    // -------------------------------------------------------------------
    // Staff ACL — a distinct area, same as the WHMCS addon's own menu entry
    // under Setup > Addon Modules, so it gets its own permission group
    // rather than attaching to a core one.
    // -------------------------------------------------------------------

    public function getPermissionGroups()
    {
        return [
            [
                'name' => Language::_('BrandforgePlugin.permission.group', true),
                'level' => 'staff',
                'alias' => 'brandforge.admin_main'
            ]
        ];
    }

    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'brandforge.admin_main',
                'name' => Language::_('BrandforgePlugin.permission.admin_main', true),
                'alias' => 'brandforge.admin_main',
                'action' => '*'
            ]
        ];
    }
}
