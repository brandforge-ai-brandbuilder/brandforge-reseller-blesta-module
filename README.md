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
| `plugin/brandforge/` (package sync / One-Click Setup admin UI, the WHMCS addon's counterpart) | **Built** — `BrandforgePlugin` handler (install/uninstall, `nav_staff` action, staff ACL), its own settings (a Godmode Account dropdown — picks an existing brandforge module row — plus a debug flag; see "One Godmode credential, not two" below for why there's no separate API URL/Key here), `BrandforgePackageMap` sync engine (fetch Godmode packages → upsert, same response-shape tolerance and preserve-link-on-resync guarantee as the WHMCS addon's `PackageSync`), and an `AdminPackageSync` page to sync/auto-create/link/unlink packages. Not WHMCS's `WhmcsServerManager` equivalent (no auto-created module row; the admin picks an existing one from the dropdown instead). |

**Verified against a live Blesta 6.0.2 install (2026-10-31 through 11-02) — nine critical bugs found and fixed, and the Package Sync feature confirmed working end-to-end against the real Godmode API (Sync Packages correctly pulled all 9 real packages with their actual plan codes and Godmode IDs, and "Create Package" successfully created a real Blesta package):**

1. **The plugin's main controller must NOT be named `AdminMain`.** It originally was (`class AdminMain`, file `controllers/admin_main.php`) — the same naming convention the Blesta plugin docs themselves show (e.g. the shipped `support_manager` plugin). On this live install, that name collided with whatever Blesta uses to resolve the bare `/admin/` dashboard route: visiting `/admin/` executed **our** plugin controller instead of Blesta's own, and since our `brandforge_settings` table didn't exist yet at that point, every request to `/admin/` fatal-errored with no error display, producing a blank screen for the **entire admin area**. Renaming the plugin's folder alone didn't fix it, confirming the resolution happens dynamically by class name against whatever is currently on disk, not a stale cache.

2. **The plugin's main controller name MUST start with `admin_`.** The first fix renamed it to `BrandforgeAdminMain` (file `brandforge_admin_main.php`) — unique, no longer collides with core, but **broke staff authentication on that one page specifically**: visiting it 302-redirected to `/admin/login/` and immediately back to the dashboard, with zero errors logged anywhere (app log, PHP-FPM log, Apache log). Diagnosed by temporarily adding `die()` debug dumps into `preAction()`: the staff session was 100% valid and correctly populated (`blesta_staff_id`, `blesta_company_id`, etc. all present and correct) at the point `requireLogin()` was called, yet `isLoggedIn()` still returned `NULL`. Every other installed plugin's working admin page we could find to compare against — `order`'s `admin_forms`, `billing_overview`'s `admin_main` — has a controller name starting with `admin_`; ours (`brandforge_admin_main`) did not. `isLoggedIn()`/`requireLogin()` appear to use the resolved controller name to decide staff-vs-client session context, and a plugin controller name without the `admin_` prefix reads as a non-admin request, which looks for a client session, finds none, and silently bounces to login.

**Fixed** by renaming the controller to `AdminPackageSync` (file `controllers/admin_package_sync.php`, view `views/default/admin_package_sync.pdt`, nav URL `plugin/brandforge/admin_package_sync/`) — satisfies both constraints: unique (no `AdminMain` collision) and `admin_`-prefixed (recognized as a staff-area controller). **If you ever rename this controller again, it must keep the `admin_` prefix and must not be exactly `AdminMain`.**

3. **Auto-rendered plugin controller views are never found under `views/default/` without an explicit fix — `setDefaultView()` alone is not enough.** Read directly from Blesta's actual `vendors/minphp/bridge/src/Lib/View.php` source: `setDefaultView($path)` only overwrites the plugin's *path prefix* (`view_path`/`default_view_path`); it never touches `View::$view` (the theme subfolder name), which stays at whatever the system's configured admin theme is — on this install, `admin/paradigm`, not `default`. Worse, `fetch()`'s own "fallback to the default view" branch reconstructs the path from `$this->default_view` (the *same* unchanged theme name), so the fallback lands on the identical wrong path too — there is no code path that ever tries `views/default/` on its own. The symptom: a clean, logged exception (`Files does not exist: .../views/admin/paradigm/{action}.pdt`) on every page load. `AdminManagePlugin` never hit this because it explicitly calls `setView(null, 'Brandforge.default')`, which *does* set `$this->view` directly. **Fixed** by setting `$this->view->view = 'default';` explicitly in `BrandforgeController::preAction()` (right after `setDefaultView()`), so every controller extending it inherits the correct theme folder — not something `reference/plugins/create.md`'s own minimal example mentions, so don't assume `setDefaultView()` alone is sufficient when porting this pattern elsewhere.

4. **Getting the real Paradigm chrome (sidebar, header, CSS) around a plugin page needs an explicit `$this->structure` restore, done *before* calling `parent::preAction()` — not after.** `Controller::render()` fetches the page's outer "structure" template through a *separate* `$this->structure` View instance from `$this->view` (confirmed by reading Blesta's actual `vendors/minphp/bridge/src/Lib/Controller.php`). Dispatcher's `setDefaultViewPath($pluginPath)` — called before `preAction()` ever runs — scopes **both** `$this->view` and `$this->structure` to our plugin directory, so without correcting it, Blesta looks for the page chrome *inside our plugin* instead of its own app directory. This took three attempts to get fully right:
   - First attempt forced `$this->structure->view = 'default'` thinking it would trigger `View::fetch()`'s fallback branch onto the real `admin/paradigm` theme — **wrong**: Blesta ships a second, genuinely different `APPDIR/views/default/structure.pdt`, a real, intentional, minimal "floating theme-toggle + raw content" wrapper with **no sidebar at all**, confirmed by inspecting the live rendered DOM and reading the file itself from the installer zip.
   - Second attempt restored `$this->structure->setDefaultView(APPDIR)` + `setView(null, $origStructureView)` *after* calling `parent::preAction()`. This got the markup right (real sidebar/top-bar HTML rendered) but every CSS/JS asset 404'd (`.../plugins/brandforge/views/admin/paradigm/css/....min.css`), leaving the page fully unstyled. Root cause, confirmed by reading `vendors/minphp/bridge/src/Lib/View.php` and `Dispatcher.php`: `AppController::preAction()` (core, encrypted) is what registers the request's CSS/JS file list, and it does so by reading `$this->structure`'s view path **at the moment it runs** — restoring the path only afterward fixes template lookup but is too late for the already-baked asset URLs.
   - **Actual fix**, found by reading the stock `order` plugin's real base controller (`plugins/order/order_controller.php`): `OrderController::preAction()` calls `$this->structure->setDefaultView(APPDIR);` **before** its own `parent::preAction()` call, not after. Replicated that exact ordering in `BrandforgeController::preAction()` — `setDefaultView(APPDIR)` first, then `parent::preAction()`, then set up `$this->view` for our own plugin content. No manual `setView()`/theme-name capture-and-restore is needed afterward: `Controller::render()` always fetches the structure view using a hardcoded theme path from `$this->structure_view` (a controller property, not `$this->structure->view`), so once `view_path` is correctly restored to `APPDIR` before `parent::preAction()` runs, both the chrome markup and the CSS/JS asset URLs resolve correctly for the rest of the request. **Confirmed live**: full sidebar, top bar, search, and a fully Paradigm-styled package table, zero 404s.
   
   The plugin-local `structure.pdt` pass-through files (`views/default/` and `views/admin/paradigm/`) are no longer used once this fix is in place, but are left in the repo as a harmless safety net.

5. **A plugin's own views can carry stale hardcoded URLs across a controller rename, even after the controller and its redirects are fully updated.** When the controller was renamed (bug #1/#2 above), every sed/rewrite pass targeted the `.php` controller file's own redirect URLs and the view's language-key prefixes — but never the view's own hardcoded button/form-action URL fragments, which had been written against the very first name (`admin_main`) and silently survived two further renames untouched. Symptom: clicking "Sync Packages" submitted to `.../plugin/brandforge/admin_main/sync_all/` (the stale, now-nonexistent path), which fell through Blesta's router into a plain client-portal 404 page — easy to misread as a theme/layout problem rather than a stale URL, since the 404 page's styling looked like a completely different part of the app. **Fixed** by grepping the view file directly for every remaining `admin_main` fragment and replacing them all. Lesson: after renaming a plugin controller, grep the *entire plugin directory* for the old name — not just the controller file — since views build their own URLs independently of the controller's redirects.

6. **`PackageGroups::add()` requires `names` as a multi-language array, not a plain `name` string** — confirmed by reading Blesta's actual `app/models/package_groups.php`: its validation rule for `names` runs `is_array($names)` first (`'final' => true`, so every other rule short-circuits on failure) and requires each entry to have `lang`/`name` keys, exactly like `Packages::add()`. `findOrCreatePackageGroup()` originally passed `'name' => 'BrandForge'` (a bare string), which failed that format check outright — error shown to the admin: *"Could not create the 'BrandForge' package group: The package group names are in an invalid format."* **Fixed** by passing `'names' => [['lang' => 'en_us', 'name' => 'BrandForge']]` (and `descriptions` in the same shape). **Confirmed live** after the fix: "Create Package" successfully created a real Blesta package (bound to the module row, in a newly-created "BrandForge" group, with `meta[plan_code]` set) end-to-end.
7. **`InputFields` is a namespaced class (`Blesta\Core\Util\Input\Fields\InputFields`), and PHP `use` imports are per-file, not inherited by subclasses.** Blesta's abstract `Module` base class (`components/modules/module.php`) has `use Blesta\Core\Util\Input\Fields\InputFields;` at the top of its own file, which is why its default `getPackageFields()`/`getAdminAddFields()`/`getClientAddFields()` (returning `new InputFields()`) work fine when inherited unmodified. Our module overrides all three but never added the same `use` statement to `brandforge.php` — so `new InputFields()` there resolved to a nonexistent bare `\InputFields` in the global namespace. Symptom: clicking "Add Service" and selecting a BrandForge package produced a blank screen; the real error only showed up in `logs_blesta`: `Error: Class "InputFields" not found in .../brandforge.php:385`, thrown from `getAdminAddFields()`. This had been sitting undetected because nothing had exercised the Add Service page until this point in testing. **Fixed** by adding `use Blesta\Core\Util\Input\Fields\InputFields;` to the top of `module/brandforge/brandforge.php`. Lesson: when a Blesta base class's default behavior "just works," check whether that's because of a per-file `use` import before assuming an override can skip it.
8. **`Record::fetch()` returns `false` on no match, not `null`** — `findOpenAccount()` passed that return value straight through, and `addService()` checked it with `if ($account !== null)`. Since `false !== null` evaluates `true`, a brand-new client with *no* existing BrandForge account still took the "attach to existing account" branch, calling `attachPlanToAccount($api, $row, false, $planCode)` with `false` standing in for the account object. Symptom: `TypeError: BrandforgeMapper::addPlanPayload(): Argument #1 ($godmodeServiceId) must be of type string, null given` — reading `->godmode_service_id` off `false` silently evaluates to `null` rather than throwing, so the real failure only surfaced one call deeper, as a type error in an unrelated-looking file. Every other `->fetch()` result in this codebase already normalizes the falsy case to an explicit `null` before comparing (`decrementAccountPlanCount()`'s `if (!$account) { return null; }`, `getModuleRow()`'s `$row ?: null`) — `findOpenAccount()` was the one spot that skipped it. **Fixed** by changing `findOpenAccount()` to `...->fetch() ?: null;`, matching that same convention.
9. **`??` doesn't fall back on an empty string — only `null`/undefined — and `buildPackageMeta()` always writes `brand_color`/`brand_name`, defaulting to `''` rather than omitting the key.** `getDashboardData()`'s `$package->meta->brand_color ?? '#6366f1'` therefore never applied its fallback for a package created via the plugin's "Create Package" (which intentionally only sets `meta[plan_code]`, leaving branding for the admin to fill in afterward) — `$package->meta->brand_color` was set, just to `''`. Symptom: the client dashboard's "Launch" button rendered as `style="background-color: ; color: #fff;"` — invisible white text with no background, present and clickable but impossible to see, with no error anywhere to explain it. The button's own height still occupied layout space, which is what made it look like the button was simply missing. Confirmed live: same service, same data, the "View Workspace" button (styled with a fixed `.btn-outline-secondary` class, no `brand_color` dependency) rendered fine the whole time. **Fixed** by switching `brand_name`/`brand_color` to `?:` instead of `??`, so an empty string is correctly treated as "unset."

`AdminManagePlugin` (the Settings > Plugins > Manage embedded controller) was left as-is throughout — per the plugin docs it's primed directly by `AdminCompanyPlugins::manage()` via an explicit plugin-id → directory lookup, not resolved by class/controller name, so it isn't exposed to the controller-naming risks above (#1/#2). That's documented, not independently verified on this install — if plugin settings ever behave strangely, this is the first thing to check.

## One Godmode credential, not two

Early versions of the plugin had their own Settings page fields for Godmode API URL/Key, entirely
separate from the brandforge **module**'s own row credentials (Settings > Company > Modules >
BrandForge > Manage) — mirroring the WHMCS addon, which also keeps `godmode_api_url`/`godmode_api_key`
independent of the server module's. On a live install this just meant typing the same URL and key
into two different settings screens for no benefit: a WHMCS install can genuinely have several
differently-configured server instances, but a Blesta company has one Godmode account per module row
in practice, so the duplication was pure friction, not real flexibility.

**Changed**: the plugin's settings page no longer asks for API URL/Key at all. Instead it shows a
dropdown of existing brandforge module rows (`#<id> — <api_url>`, populated via
`ModuleManager::getRows()`); Package Sync fetches that row's credentials — already decrypted by
`ModuleManager::getRow()` — at request time instead of storing a second copy. `brandforge_settings`
now only stores `module_row_id` and a debug flag. If you have an existing install from before this
change, delete and reinstall the plugin (Settings > Company > Plugins) to pick up the new table
schema, then re-select your module row on the settings page.

**Not otherwise verified beyond the above.** What hasn't been exercised yet:

- `module/brandforge/brandforge.php`'s `install()`/`brandforge_accounts` CRUD, and the plugin's own
  `brandforge_settings`/`brandforge_package_map` CRUD — all plain `$this->Record` query-builder calls
  following Blesta's documented conventions, low risk.
- The "Link existing package" and "Reset Mapping" actions, and editing an existing module row — not
  yet clicked through on this install.
- The whole service lifecycle: ordering a package, SSO launch, upgrade/change package, suspend,
  unsuspend, and cancel/terminate — code matches the Blesta `Module` contract (verified against
  `components/modules/module.php` and the `order`/`cpanel` core modules) but has not been clicked
  through end-to-end on a live install yet.
- The client dashboard tab's "Upgrade Plan" button links to `services/upgrade/{service_id}/` — the
  real Blesta client-portal route for `ClientServices::upgrade()` (confirmed by reading
  `app/controllers/client_services.php`), but not yet clicked on a live install. That action is also
  gated by the company setting *client can change service package* (`client_change_service_package`)
  — if the button 404s or goes nowhere, check that setting first before assuming a module bug.

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
3. Test the plugin end-to-end: select the module row under Settings > Company > Plugins
   > Manage, Sync Packages, Create Package for at least one Godmode plan, confirm the
   resulting Blesta package's `meta[plan_code]` is set and it binds to the right module
   row, then set real pricing on it (auto-create defaults to $0/month, same as WHMCS's
   placeholder-product approach needing a manual price afterward). Also confirm
   re-running Sync/Create Package never duplicates a package or package group (the
   idempotency guarantee ported from `WhmcsProductManager`), and exercise the remaining
   untested actions (Link existing package, Reset Mapping, editing a module row).
4. Once `getBrandforgeModuleId()`/`ModuleManager` and the Packages/PackageGroups calls
   are confirmed against core source, consider automating the Module Row ID field
   (currently manual entry) by listing the installed module's rows for the admin to pick
   from, closing the one deliberate gap vs. WHMCS's `WhmcsServerManager`.
5. Flag the Godmode-side `billing_platform` gap above to whoever owns `godmode` so it
   gets scheduled — it also unblocks the Upmind integration.

## License

Proprietary — BrandForge. All rights reserved.
