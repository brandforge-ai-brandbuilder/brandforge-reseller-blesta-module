<?php
/**
 * Builds a one-time BrandForge SSO login URL from a Godmode service_id.
 *
 * Direct port of the WHMCS module's BrandForge\SsoHandler
 * (brandforge-reseller-whmcs-module/modules/servers/brandforge/lib/SsoHandler.php) —
 * pure business logic, unchanged behaviour.
 */
class BrandforgeSso
{
    private BrandforgeClient $client;

    public function __construct(BrandforgeClient $client)
    {
        $this->client = $client;
    }

    /**
     * If $frontendBaseUrl is supplied, the scheme+host Godmode returns is
     * replaced with it so the customer lands on the reseller's branded
     * domain instead of brandforge.software.
     *
     * @throws RuntimeException        When the API returns no URL
     * @throws BrandforgeApiException  On API-level failures
     */
    public function getLoginUrl(string $serviceId, string $returnPath = '', string $frontendBaseUrl = ''): string
    {
        $response = $this->client->sso($serviceId, $returnPath);

        $data = $response['data'] ?? $response;
        $url = $data['url'] ?? $data['login_url'] ?? $data['sso_url'] ?? '';

        if ($url === '') {
            throw new RuntimeException(
                'Godmode SSO endpoint returned a successful response but no login URL was found.'
            );
        }

        // Godmode returns user/entity/package/credits/reseller alongside the
        // token. Encode them as a base64url query param so the frontend can
        // skip an extra round-trip to Godmode.
        $profile = [];
        foreach (['user', 'entity', 'package', 'credits', 'reseller'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $profile[$key] = $data[$key];
            }
        }
        if (!empty($profile)) {
            $encoded = rtrim(strtr(base64_encode(json_encode($profile, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'profile=' . $encoded;
        }

        $frontendBaseUrl = rtrim(trim($frontendBaseUrl), '/');
        if ($frontendBaseUrl !== '') {
            $parsed = parse_url($url);
            $pathAndQuery = ($parsed['path'] ?? '')
                . (isset($parsed['query']) ? '?' . $parsed['query'] : '')
                . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
            $url = $frontendBaseUrl . $pathAndQuery;
        }

        return $url;
    }
}
