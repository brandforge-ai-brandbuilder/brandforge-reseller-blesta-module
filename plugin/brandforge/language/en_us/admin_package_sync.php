<?php
$lang['AdminPackageSync.page_title'] = 'BrandForge Package Sync';

$lang['AdminPackageSync.status.credentials'] = 'API Credentials';
$lang['AdminPackageSync.status.credentials.saved'] = 'Saved';
$lang['AdminPackageSync.status.credentials.missing'] = 'Not set';
$lang['AdminPackageSync.status.packages'] = 'Packages';
$lang['AdminPackageSync.status.packages.synced'] = '%1$s synced';
$lang['AdminPackageSync.status.packages.none'] = 'Not synced';
$lang['AdminPackageSync.status.linked'] = 'Linked';
$lang['AdminPackageSync.status.linked.ready'] = '%1$s / %2$s ready';
$lang['AdminPackageSync.status.linked.partial'] = '%1$s / %2$s linked';

$lang['AdminPackageSync.need_credentials'] = 'Select a BrandForge module row (Godmode account) under Settings > Company > Plugins > Manage before syncing.';
$lang['AdminPackageSync.go_to_settings'] = 'Go to Settings';

$lang['AdminPackageSync.btn.sync_all'] = 'Sync Packages';
$lang['AdminPackageSync.btn.reset'] = 'Reset Mapping';
$lang['AdminPackageSync.btn.reset.confirm'] = 'This clears the local package mapping. Any Blesta packages you already created are kept — you can re-sync and re-link them afterward. Continue?';

$lang['AdminPackageSync.stat.total'] = 'Total';
$lang['AdminPackageSync.stat.linked'] = 'Linked';
$lang['AdminPackageSync.stat.pending'] = 'Pending';

$lang['AdminPackageSync.table.empty'] = 'No packages synced yet. Click "Sync Packages" to pull the catalog from Godmode.';
$lang['AdminPackageSync.col.name'] = 'Package Name';
$lang['AdminPackageSync.col.slug'] = 'Plan Code';
$lang['AdminPackageSync.col.godmode_id'] = 'Godmode ID';
$lang['AdminPackageSync.col.blesta_package'] = 'Blesta Package';
$lang['AdminPackageSync.col.status'] = 'Status';
$lang['AdminPackageSync.col.actions'] = 'Actions';

$lang['AdminPackageSync.status.linked_label'] = 'Linked';
$lang['AdminPackageSync.status.pending_label'] = 'Pending';

$lang['AdminPackageSync.action.auto_create'] = 'Create Package';
$lang['AdminPackageSync.action.link'] = 'Link';
$lang['AdminPackageSync.action.link_placeholder'] = 'Select an existing package…';
$lang['AdminPackageSync.action.unlink'] = 'Unlink';
$lang['AdminPackageSync.action.unlink.confirm'] = 'Unlink "%1$s" from its Blesta package? The Blesta package itself is not deleted.';

$lang['AdminPackageSync.flash.no_credentials'] = 'Select a BrandForge module row (Godmode account) under Settings > Company > Plugins > Manage first.';
$lang['AdminPackageSync.flash.sync_success'] = 'Synced %1$s package(s) from Godmode.';
$lang['AdminPackageSync.flash.sync_partial'] = 'Synced %1$s package(s), with errors: %2$s';
$lang['AdminPackageSync.flash.sync_failed'] = 'Could not reach Godmode: %1$s';
$lang['AdminPackageSync.flash.package_not_found'] = 'That package is not in the local mapping — try syncing first.';
$lang['AdminPackageSync.flash.already_linked'] = 'That package is already linked to a Blesta package.';
$lang['AdminPackageSync.flash.no_module_row'] = 'Set a Module Row ID under Settings > Company > Plugins > Manage before creating packages.';
$lang['AdminPackageSync.flash.module_not_installed'] = 'The BrandForge module is not installed — install it under Settings > Company > Modules first.';
$lang['AdminPackageSync.flash.package_created'] = 'Created a Blesta package for "%1$s". Set its real pricing under Packages.';
$lang['AdminPackageSync.flash.create_failed'] = 'Could not create the Blesta package: %1$s';
$lang['AdminPackageSync.flash.select_package'] = 'Select a Blesta package to link to.';
$lang['AdminPackageSync.flash.linked'] = 'Package linked.';
$lang['AdminPackageSync.flash.unlinked'] = 'Package unlinked.';
$lang['AdminPackageSync.flash.reset_complete'] = 'Package mapping reset. Any Blesta packages you already created were kept.';
