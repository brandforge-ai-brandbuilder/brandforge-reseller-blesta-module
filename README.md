# BrandForge Blesta Extension

Blesta 6.0 provisioning module + companion admin plugin that connect Blesta's product
lifecycle to the [BrandForge](https://brandforge.software) Godmode API — the Blesta
counterpart to `brandforge-reseller-whmcs-module`.

## Status: both extensions built, unverified against a live install

| Piece | Status |
|---|---|
| `module/brandforge/apis/` (HTTP client, payload mapper, exceptions, SSO URL builder) | Ported from the WHMCS module's `lib/` — pure business logic, no framework dependency |
| `module/brandforge/config.json`, `composer.json`, language file | Scaffolded, extended with row/package/error strings |
| `module/brandforge/brandforge.php` (the actual `Module` class) | **Built** — install/uninstall (local `brandforge_accounts` table), module row (Godmode API URL/Key + connection test), package fields (plan code + branding), full service lifecycle (addService with create-vs-attach stacking, suspend/unsuspend/cancel as plan-scoped calls, changeServicePackage as add-then-remove) |
| `module/brandforge/views/default/{add_row,edit_row}.pdt` | Built (plain Bootstrap 5 markup, no Form-helper dependency) |
| SSO / usage-dashboard service tabs | Built — `getAdminServiceTabs`/`getClientServiceTabs` + `tabAdminDashboard`/`tabClientDashboard`, one combined "Launch" + "View Workspace" + credits/workspaces dashboard per side. Admin view is Bootstrap 5/Paradigm (`bi bi-…` icons); client view stays Bootstrap 4 + FontAwesome (`fas fa-…`) for the 6.0 client portal, per `reference/common/conversion-ui.md`. |
| `plugin/brandforge/` (package sync / One-Click Setup admin UI, the WHMCS addon's counterpart) | **Built** — `BrandforgePlugin` handler (install/uninstall, `nav_staff` action, staff ACL), its own settings (API URL/Key/debug/Module Row ID, separate from the module's row credentials — mirrors the WHMCS addon), `BrandforgePackageMap` sync engine (fetch Godmode packages → upsert, same response-shape tolerance and preserve-link-on-resync guarantee as the WHMCS addon's `PackageSync`), and an `AdminMain` page to sync/auto-create/link/unlink packages. See "Known risk" below — not WHMCS's `WhmcsServerManager` equivalent (no auto-created module row; the admin enters a Module Row ID by hand instead). |

**Not yet verified against a real Blesta 6.0 install.** No Blesta instance is available in this environment, so nothing here has actually been installed and clicked through. Two different risk levels:

- **Low risk** — `module/brandforge/brandforge.php`'s `install()`/`brandforge_accounts` CRUD, and the plugin's own `brandforge_settings`/`brandforge_package_map` CRUD: all plain `$this->Record` query-builder calls following Blesta's documented conventions, but unconfirmed against core source in this environment.
- **Higher risk** — `plugin/brandforge/models/brandforge_package_map.php`'s `findOrCreatePackageGroup()`/`findExistingPackageByName()`/`createPackage()` (the "Create Package" button, WHMCS's `WhmcsProductManager` equivalent): these call Blesta's `Packages`/`PackageGroups` models with a field shape (`names[]`, `pricing[]`, `module_row`, `meta[plan_code]`, etc.) based on general Blesta model conventions, **not confirmed against `app/models/packages.php`** on a live install. Sanity-check this one action on a real Blesta 6 dev instance before relying on it in production — everything else only touches APIs already covered above.

## Structure

```
module/brandforge/     # extends Module — provisioning (create/suspend/unsuspend/terminate/SSO)
plugin/brandforge/     # extends Plugin — admin package-sync UI (WHMCS addon module's counterpart)
```

Mirrors the WHMCS repo's `modules/servers/brandforge` + `modules/addons/brandforge` split — Blesta
calls the same idea Module + Plugin instead of Server + Addon.

## Known cross-repo gap — Godmode API is not fully platform-agnostic

`POST /provision/create` and `/add_plan` accept generic params (`email`, `plan_code`,
`service_id`) that work fine here, but the WHMCS module additionally round-tripped
`whmcs_client_id`/`whmcs_product_id`/`whmcs_service_id` so Godmode could build WHMCS
deep-links (`cart.php`/`upgrade.php`) without querying WHMCS's own DB. Godmode's schema
(`EndClient`/`EndClientPlan`/`Package`) has **only** WHMCS-shaped columns — no generic
`billing_platform`/`external_id` concept yet (same gap already identified for a prior
Upmind integration investigation).

This module **omits those fields** rather than invent an unsupported contract
(`apis/brandforge_mapper.php` has the detail). Effect: provisioning (create, suspend,
terminate, SSO, etc.) works fully end-to-end. Any Godmode-side UI/logic that builds a
"manage this customer's plan" deep-link for a Blesta-provisioned customer will not have
one until `godmode` (Rails) adds a generic billing-platform concept and Blesta-aware
link generation. Track that as separate `godmode` work, not a client-side workaround.

## Next steps

1. Install both extensions on a real Blesta 6.0 dev instance. Sanity-check, in order of
   risk: (a) `plugin/brandforge/models/brandforge_package_map.php`'s `Packages`/
   `PackageGroups` calls behind the "Create Package" button — highest risk, see above;
   (b) `module/brandforge/brandforge.php`'s `install()`/`brandforge_accounts` `Record`
   calls; (c) the plugin's own `brandforge_settings`/`brandforge_package_map` `Record`
   calls.
2. Test the full module lifecycle (add, suspend, unsuspend, cancel, change package, the
   multi-package stacking path, and the two dashboard tabs) against a real Godmode
   sandbox on the Blesta dev license.
3. Test the plugin end-to-end: enter settings (API URL/Key/Module Row ID) under
   Settings > Company > Plugins > Manage, Sync Packages, Create Package for at least
   one Godmode plan, confirm the resulting Blesta package's `meta[plan_code]` is set and
   it binds to the right module row, then set real pricing on it (auto-create defaults
   to $0/month, same as WHMCS's placeholder-product approach needing a manual price
   afterward). Also confirm re-running Sync/Create Package never duplicates a package or
   package group (the idempotency guarantee ported from `WhmcsProductManager`).
4. Once `getBrandforgeModuleId()`/`ModuleManager` and the Packages/PackageGroups calls
   are confirmed against core source, consider automating the Module Row ID field
   (currently manual entry) by listing the installed module's rows for the admin to pick
   from, closing the one deliberate gap vs. WHMCS's `WhmcsServerManager`.
5. Flag the Godmode-side `billing_platform` gap above to whoever owns `godmode` so it
   gets scheduled — it also unblocks the Upmind integration.

## License

Proprietary — BrandForge. All rights reserved.
