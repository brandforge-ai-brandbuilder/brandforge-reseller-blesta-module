# BrandForge Blesta Extension

Blesta 6.0 provisioning module + companion admin plugin that connect Blesta's product
lifecycle to the [BrandForge](https://brandforge.software) Godmode API — the Blesta
counterpart to `brandforge-reseller-whmcs-module`.

## Status: scaffolding in progress

| Piece | Status |
|---|---|
| `module/brandforge/apis/` (HTTP client, payload mapper, exceptions, SSO URL builder) | Ported from the WHMCS module's `lib/` — pure business logic, no framework dependency |
| `module/brandforge/config.json`, `composer.json`, language file | Scaffolded, extended with row/package/error strings |
| `module/brandforge/brandforge.php` (the actual `Module` class) | **Built** — install/uninstall (local `brandforge_accounts` table), module row (Godmode API URL/Key + connection test), package fields (plan code + branding), full service lifecycle (addService with create-vs-attach stacking, suspend/unsuspend/cancel as plan-scoped calls, changeServicePackage as add-then-remove) |
| `module/brandforge/views/default/{add_row,edit_row}.pdt` | Built (plain Bootstrap 5 markup, no Form-helper dependency) |
| SSO / usage-dashboard service tabs | Built — `getAdminServiceTabs`/`getClientServiceTabs` + `tabAdminDashboard`/`tabClientDashboard`, one combined "Launch" + "View Workspace" + credits/workspaces dashboard per side. Admin view is Bootstrap 5/Paradigm (`bi bi-…` icons); client view stays Bootstrap 4 + FontAwesome (`fas fa-…`) for the 6.0 client portal, per `reference/common/conversion-ui.md`. |
| `plugin/brandforge/` (package sync / One-Click Setup admin UI, the WHMCS addon's counterpart) | Directory scaffolded only |

**Not yet verified against a real Blesta 6.0 install.** The `Record` component calls in `brandforge.php`
(`select()->from()->where()->order()->fetch()`, `insert()`, `where()->update()`) follow Blesta's documented
query-builder conventions but were written without access to Blesta core source in this environment —
sanity-check `install()` and the `brandforge_accounts` read/write paths against a live dev instance before
relying on them.

## Structure

```
module/brandforge/     # extends Module — provisioning (create/suspend/unsuspend/terminate/SSO)
plugin/brandforge/      # extends Plugin — admin package-sync UI (WHMCS addon module's counterpart)
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

1. Install the module on a real Blesta 6.0 dev instance and sanity-check the `Record`
   query-builder calls in `brandforge.php` (`install()`'s `CREATE TABLE`, and every
   `brandforge_accounts` read/write) — written from documented conventions, not
   verified against Blesta core source in this environment.
2. `plugin/brandforge/` — One-Click Setup / package sync admin page (WHMCS addon's
   counterpart), with its own package-mapping table and `AdminMain` controller.
3. Test the full lifecycle (add, suspend, unsuspend, cancel, change package, the
   multi-package stacking path, and the two dashboard tabs) against a real Godmode
   sandbox on the Blesta dev license.
4. Flag the Godmode-side `billing_platform` gap above to whoever owns `godmode` so it
   gets scheduled — it also unblocks the Upmind integration.

## License

Proprietary — BrandForge. All rights reserved.
