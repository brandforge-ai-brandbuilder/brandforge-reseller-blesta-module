<?php
require_once dirname(__FILE__) . DS . 'brandforge_sync_exceptions.php';

/**
 * Minimal Godmode HTTP client for the Package Sync plugin — only what this
 * admin page needs (connection test + the package catalog). The full
 * provisioning client lives in the module (module/brandforge/apis/
 * brandforge_client.php); this is a separate, smaller copy, not a shared
 * dependency — see brandforge_sync_exceptions.php's docblock for why.
 */
class BrandforgeSyncClient
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

    public function testConnection(): array
    {
        return $this->request('GET', '/api/godmode/v1/provision/ping');
    }

    public function getPackages(): array
    {
        return $this->request('GET', '/api/godmode/v1/provision/packages');
    }

    /**
     * @throws BrandforgeSyncApiException
     */
    private function request(string $method, string $path): array
    {
        $url = $this->apiUrl . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        unset($ch);

        if ($curlErrno !== 0) {
            throw new BrandforgeSyncApiException("cURL error [{$curlErrno}]: {$curlError}", 0);
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            throw new BrandforgeSyncApiException('Invalid JSON response from Godmode API.', $statusCode);
        }

        if ($statusCode === 401) {
            throw new BrandforgeSyncApiException('Unauthorized — check the Godmode API Key.', $statusCode);
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error'] ?? "API error {$statusCode}";
            throw new BrandforgeSyncApiException($message, $statusCode);
        }

        return $decoded;
    }
}
