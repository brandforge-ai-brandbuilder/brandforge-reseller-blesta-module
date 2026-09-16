<?php
require_once dirname(__FILE__) . DS . 'brandforge_exceptions.php';

/**
 * HTTP client for the Godmode provisioning API.
 *
 * Ported from the WHMCS module's BrandForge\GodmodeClient
 * (brandforge-reseller-whmcs-module/modules/servers/brandforge/lib/GodmodeClient.php).
 * The curl/error-handling logic is unchanged; what's dropped is the WHMCS-side
 * Logger dependency — in Blesta, request/response logging is the calling
 * Module's job via the inherited $this->log(), not this client's (see
 * reference/modules/create.md §13). Call maskPayload() before logging a
 * request from the Module class.
 *
 * KNOWN GAP: the WHMCS Mapper round-tripped whmcs_client_id/whmcs_product_id/
 * whmcs_service_id in the create/add_plan payloads so Godmode could build
 * WHMCS deep-links (cart.php/upgrade.php) without querying WHMCS's DB.
 * Godmode's API and schema have no platform-agnostic equivalent yet (same
 * gap already identified for an Upmind integration) — this client
 * deliberately omits those fields rather than invent a contract Godmode
 * doesn't support. Effect: provisioning works fully; any Godmode-side UI
 * that builds a "manage this customer's plan" deep-link for a
 * Blesta-provisioned customer will not have one until Godmode adds a
 * generic billing_platform/external_id concept. Track and fix on the
 * Godmode (Rails) side, not by hacking around it here.
 */
class BrandforgeClient
{
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT = 10;

    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    // -----------------------------------------------------------------------
    // Public API actions
    // -----------------------------------------------------------------------

    public function testConnection(): array
    {
        return $this->request('GET', '/api/godmode/v1/provision/ping', [], 'TestConnection');
    }

    public function createAccount(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/create', $payload, 'CreateAccount');
    }

    public function suspendAccount(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/suspend', $payload, 'SuspendAccount');
    }

    public function unsuspendAccount(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/unsuspend', $payload, 'UnsuspendAccount');
    }

    public function terminateAccount(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/terminate', $payload, 'TerminateAccount');
    }

    /**
     * Attaches an additional plan to an existing account. Response includes
     * an active_plans[] list but NOT workspace_id/user_id — callers reuse
     * those from whichever existing service row they resolved the target
     * account through.
     */
    public function addPlan(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/add_plan', $payload, 'AddPlan');
    }

    /** Suspends ONE plan on the account — siblings, and account-level status, are untouched. */
    public function suspendPlan(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/suspend_plan', $payload, 'SuspendPlan');
    }

    /**
     * Reactivates ONE suspended plan — never call this expecting it to touch
     * account-level status or any sibling plan. That's exactly the property
     * that makes it safe: reactivating one paid-up plan must never
     * accidentally reactivate a different, still-unpaid one.
     */
    public function unsuspendPlan(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/unsuspend_plan', $payload, 'UnsuspendPlan');
    }

    /** Terminates (archives) ONE plan. Godmode reassigns primary to another active plan if this one was it. */
    public function terminatePlan(array $payload): array
    {
        return $this->request('POST', '/api/godmode/v1/provision/terminate_plan', $payload, 'TerminatePlan');
    }

    public function getPackages(): array
    {
        return $this->request('GET', '/api/godmode/v1/provision/packages', [], 'GetPackages');
    }

    /** Fetch live service info: credits, workspaces, and package limits. */
    public function getServiceInfo(string $serviceId): array
    {
        return $this->request(
            'GET',
            '/api/godmode/v1/provision/status?service_id=' . urlencode($serviceId),
            [],
            'GetServiceInfo'
        );
    }

    /**
     * Exchange a subscription ID for a one-time SSO login URL.
     *
     * @param string $returnPath Optional path on the BrandForge app to land on after login.
     */
    public function sso(string $serviceId, string $returnPath = ''): array
    {
        $payload = ['service_id' => $serviceId];
        if ($returnPath !== '') {
            $payload['return_path'] = $returnPath;
        }

        return $this->request('POST', '/api/godmode/v1/provision/sso', $payload, 'SSO');
    }

    // -----------------------------------------------------------------------
    // Core HTTP
    // -----------------------------------------------------------------------

    /**
     * @throws BrandforgeApiException
     */
    private function request(string $method, string $path, array $payload, string $action): array
    {
        $url = $this->apiUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_UNRESTRICTED_AUTH => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($payload)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        }

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        unset($ch);

        if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
            throw new BrandforgeTimeoutException("Request timed out for action: {$action}", 0);
        }

        if ($curlErrno !== 0) {
            throw new BrandforgeConnectionException("cURL error [{$curlErrno}]: {$curlError}", 0);
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            throw new BrandforgeApiException("Invalid JSON response from Godmode API for action: {$action}", $statusCode);
        }

        if ($statusCode === 401) {
            throw new BrandforgeAuthException('Unauthorized — check your Godmode API Key', $statusCode, $decoded);
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? "API error {$statusCode}";
            throw new BrandforgeApiException($message, $statusCode, $decoded);
        }

        return $decoded;
    }

    /**
     * Replace sensitive values before a caller logs this payload.
     * Extend $sensitiveKeys if a future payload ever carries another secret.
     */
    public static function maskPayload(array $payload): array
    {
        $sensitiveKeys = ['password'];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $payload[$key] = '***REDACTED***';
            }
        }

        return $payload;
    }
}
