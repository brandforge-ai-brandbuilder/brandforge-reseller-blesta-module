<?php
$lang['Brandforge.name'] = 'BrandForge';
$lang['Brandforge.description'] = 'Provisions and manages BrandForge workspaces via the Godmode API.';

$lang['Brandforge.module_row'] = 'Godmode Account';
$lang['Brandforge.module_row_plural'] = 'Godmode Accounts';
$lang['Brandforge.module_group'] = 'Godmode Account Group';

// Module row (server) fields
$lang['Brandforge.row_meta.api_url'] = 'Godmode API URL';
$lang['Brandforge.row_meta.api_key'] = 'Godmode API Key';

// Package meta fields (branding — per-package, mirrors the WHMCS module's
// per-product Module Settings)
$lang['Brandforge.package_meta.plan_code'] = 'Godmode Plan Code';
$lang['Brandforge.package_meta.plan_code.tooltip'] = 'The plan slug from Godmode\'s package list (Provisioning > Packages). Package Sync (the companion plugin) can fill this in for you once it exists.';
$lang['Brandforge.package_meta.brand_name'] = 'Brand Name';
$lang['Brandforge.package_meta.brand_color'] = 'Brand Primary Color';
$lang['Brandforge.package_meta.brand_color_secondary'] = 'Brand Accent Color';
$lang['Brandforge.package_meta.frontend_url'] = 'Frontend App URL';
$lang['Brandforge.package_meta.client_area_language'] = 'Client Area Language';

// Service info
$lang['Brandforge.service_info.service_id'] = 'Godmode Service ID';

// Dashboard tab (SSO launch + usage)
$lang['Brandforge.tab.dashboard'] = 'BrandForge';
$lang['Brandforge.dashboard.launch'] = 'Launch %1$s';
$lang['Brandforge.dashboard.view_workspace'] = 'View Workspace';
$lang['Brandforge.dashboard.no_service'] = 'This service has not been provisioned yet.';
$lang['Brandforge.dashboard.sso_unavailable'] = 'Unable to generate a login link right now: %1$s';
$lang['Brandforge.dashboard.credits'] = 'Credits';
$lang['Brandforge.dashboard.credits_used_of'] = '%1$s of %2$s used';
$lang['Brandforge.dashboard.credits_resets'] = 'Resets %1$s';
$lang['Brandforge.dashboard.workspaces'] = 'Workspaces';
$lang['Brandforge.dashboard.workspaces_active_of_max'] = '%1$s of %2$s active';
$lang['Brandforge.dashboard.workspaces_active_count'] = '%1$s active';
$lang['Brandforge.dashboard.service_id'] = 'Godmode Service ID';
$lang['Brandforge.dashboard.usage_unavailable'] = 'Usage data is temporarily unavailable.';

// Manage page
$lang['Brandforge.manage.description'] = 'Godmode accounts (servers) connect this module to a Godmode API. Add one, then link a package to a Godmode Plan Code to start provisioning.';

// Errors
$lang['Brandforge.!error.module_row.missing'] = 'No Godmode account is configured for this module.';
$lang['Brandforge.!error.plan_code.missing'] = 'This package has no Godmode Plan Code set.';
$lang['Brandforge.!error.client_id.invalid'] = 'A valid client is required.';
$lang['Brandforge.!error.api.general'] = 'The Godmode API request failed: %1$s';
$lang['Brandforge.!error.api.no_service_id'] = 'Account provisioned but Godmode returned no service_id. Check the module log.';
$lang['Brandforge.!error.row.api_url.empty'] = 'The Godmode API URL is required.';
$lang['Brandforge.!error.row.api_url.invalid'] = 'The Godmode API URL must start with http:// or https://.';
$lang['Brandforge.!error.row.api_key.empty'] = 'The Godmode API Key is required.';
$lang['Brandforge.!error.row.test_connection'] = 'Could not connect to Godmode with these credentials: %1$s';
