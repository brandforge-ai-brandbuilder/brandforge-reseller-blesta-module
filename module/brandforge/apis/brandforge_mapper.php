<?php
/**
 * Builds Godmode API request payloads from Blesta data.
 *
 * Ported from the WHMCS module's BrandForge\Mapper
 * (brandforge-reseller-whmcs-module/modules/servers/brandforge/lib/Mapper.php).
 *
 * Field names match the actual Godmode API contract (verified 2026-07-20 on
 * the WHMCS side):
 *  - create   : flat body, plan_code = slug from provision/packages
 *  - lifecycle: service_id (Godmode's UUID for the provisioned account)
 *  - change   : service_id + plan_code (slug of the new plan)
 *
 * DELIBERATELY DROPPED vs. the WHMCS version: whmcs_client_id /
 * whmcs_product_id / whmcs_service_id. Those existed solely so Godmode could
 * build WHMCS deep-links (cart.php/upgrade.php) without querying WHMCS's own
 * DB. Godmode has no platform-agnostic equivalent yet (see
 * brandforge_client.php's class doc comment) — sending Blesta IDs under
 * those WHMCS-named keys would be actively misleading, so this Mapper omits
 * them rather than invent an unsupported contract. Effect: provisioning
 * works fully; Godmode-side deep-links for Blesta customers won't exist
 * until Godmode adds a generic billing_platform/external_id concept.
 */
class BrandforgeMapper
{
    /**
     * POST /api/godmode/v1/provision/create
     *
     * @param array  $clientDetails ['email','first_name','last_name','company_name'] — from the Clients model
     * @param string $planCode      Godmode slug (package meta 'plan_code')
     * @param string $password      The client's Blesta account password, if available, so Godmode can
     *                              provision the BrandForge account with matching credentials. Optional —
     *                              Blesta does not hand a module the client's plaintext password the way
     *                              WHMCS's $params['password'] did; leave blank if unavailable and let
     *                              Godmode/BrandForge issue its own credential (e.g. via SSO-only access).
     */
    public static function createAccountPayload(array $clientDetails, string $planCode, string $password = ''): array
    {
        return [
            'email' => $clientDetails['email'] ?? '',
            'first_name' => $clientDetails['first_name'] ?? '',
            'last_name' => $clientDetails['last_name'] ?? '',
            'company_name' => $clientDetails['company_name'] ?? '',
            'plan_code' => $planCode,
            'password' => $password,
        ];
    }

    /**
     * POST /api/godmode/v1/provision/suspend
     * POST /api/godmode/v1/provision/unsuspend
     * POST /api/godmode/v1/provision/terminate
     */
    public static function servicePayload(string $godmodeServiceId): array
    {
        return ['service_id' => $godmodeServiceId];
    }

    /**
     * POST /api/godmode/v1/provision/change_package
     *
     * Not called by this module's changeServicePackage() — change_package
     * replaces EVERY active plan on the account, not just one, which is
     * unsafe once a customer can hold more than one package. The module
     * composes addPlan() + terminatePlan() for a single-plan swap instead.
     * Kept here since it's still a valid Godmode endpoint.
     */
    public static function changePackagePayload(string $godmodeServiceId, string $planCode): array
    {
        return [
            'service_id' => $godmodeServiceId,
            'plan_code' => $planCode,
        ];
    }

    /**
     * POST /api/godmode/v1/provision/suspend_plan
     * POST /api/godmode/v1/provision/unsuspend_plan
     * POST /api/godmode/v1/provision/terminate_plan
     *
     * @param string $godmodeServiceId The ACCOUNT's service_id — shared by every package a customer
     *                                 holds, not specific to any one Blesta service.
     * @param string $planCode         The one plan_code this call acts on.
     */
    public static function planPayload(string $godmodeServiceId, string $planCode): array
    {
        return [
            'service_id' => $godmodeServiceId,
            'plan_code' => $planCode,
        ];
    }

    /**
     * POST /api/godmode/v1/provision/add_plan
     *
     * Used both when a customer's second-or-later package attaches to their
     * existing account, and for the "new plan" half of a package-change
     * swap (see changeServicePackage() in brandforge.php).
     */
    public static function addPlanPayload(string $godmodeServiceId, string $planCode): array
    {
        return [
            'service_id' => $godmodeServiceId,
            'plan_code' => $planCode,
        ];
    }
}
