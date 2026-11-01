<?php
$lang['AdminMain.page_title'] = 'BrandForge Package Sync';

$lang['AdminMain.status.credentials'] = 'API Credentials';
$lang['AdminMain.status.credentials.saved'] = 'Saved';
$lang['AdminMain.status.credentials.missing'] = 'Not set';
$lang['AdminMain.status.packages'] = 'Packages';
$lang['AdminMain.status.packages.synced'] = '%1$s synced';
$lang['AdminMain.status.packages.none'] = 'Not synced';
$lang['AdminMain.status.linked'] = 'Linked';
$lang['AdminMain.status.linked.ready'] = '%1$s / %2$s ready';
$lang['AdminMain.status.linked.partial'] = '%1$s / %2$s linked';

$lang['AdminMain.need_credentials'] = 'Set your Godmode API URL and Key under Settings > Company > Plugins > Manage before syncing.';
$lang['AdminMain.go_to_settings'] = 'Go to Settings';

$lang['AdminMain.btn.sync_all'] = 'Sync Packages';
$lang['AdminMain.btn.reset'] = 'Reset Mapping';
$lang['AdminMain.btn.reset.confirm'] = 'This clears the local package mapping. Any Blesta packages you already created are kept — you can re-sync and re-link them afterward. Continue?';

$lang['AdminMain.stat.total'] = 'Total';
$lang['AdminMain.stat.linked'] = 'Linked';
$lang['AdminMain.stat.pending'] = 'Pending';

$lang['AdminMain.table.empty'] = 'No packages synced yet. Click "Sync Packages" to pull the catalog from Godmode.';
$lang['AdminMain.col.name'] = 'Package Name';
$lang['AdminMain.col.slug'] = 'Plan Code';
$lang['AdminMain.col.godmode_id'] = 'Godmode ID';
$lang['AdminMain.col.blesta_package'] = 'Blesta Package';
$lang['AdminMain.col.status'] = 'Status';
$lang['AdminMain.col.actions'] = 'Actions';

$lang['AdminMain.status.linked_label'] = 'Linked';
$lang['AdminMain.status.pending_label'] = 'Pending';

$lang['AdminMain.action.auto_create'] = 'Create Package';
$lang['AdminMain.action.link'] = 'Link';
$lang['AdminMain.action.link_placeholder'] = 'Select an existing package…';
$lang['AdminMain.action.unlink'] = 'Unlink';
$lang['AdminMain.action.unlink.confirm'] = 'Unlink "%1$s" from its Blesta package? The Blesta package itself is not deleted.';

$lang['AdminMain.flash.no_credentials'] = 'Set your Godmode API URL and Key under Settings > Company > Plugins > Manage first.';
$lang['AdminMain.flash.sync_success'] = 'Synced %1$s package(s) from Godmode.';
$lang['AdminMain.flash.sync_partial'] = 'Synced %1$s package(s), with errors: %2$s';
$lang['AdminMain.flash.sync_failed'] = 'Could not reach Godmode: %1$s';
$lang['AdminMain.flash.package_not_found'] = 'That package is not in the local mapping — try syncing first.';
$lang['AdminMain.flash.already_linked'] = 'That package is already linked to a Blesta package.';
$lang['AdminMain.flash.no_module_row'] = 'Set a Module Row ID under Settings > Company > Plugins > Manage before creating packages.';
$lang['AdminMain.flash.module_not_installed'] = 'The BrandForge module is not installed — install it under Settings > Company > Modules first.';
$lang['AdminMain.flash.package_created'] = 'Created a Blesta package for "%1$s". Set its real pricing under Packages.';
$lang['AdminMain.flash.create_failed'] = 'Could not create the Blesta package: %1$s';
$lang['AdminMain.flash.select_package'] = 'Select a Blesta package to link to.';
$lang['AdminMain.flash.linked'] = 'Package linked.';
$lang['AdminMain.flash.unlinked'] = 'Package unlinked.';
$lang['AdminMain.flash.reset_complete'] = 'Package mapping reset. Any Blesta packages you already created were kept.';
