<?php
/**
 * Exception hierarchy for Godmode API failures.
 *
 * Direct port of the WHMCS module's BrandForge\Exceptions namespace
 * (brandforge-reseller-whmcs-module/modules/servers/brandforge/lib/Exceptions.php) —
 * pure business logic, no WHMCS or Blesta framework dependency.
 */

class BrandforgeApiException extends RuntimeException
{
    private int $statusCode;
    private array $responseBody;

    public function __construct(string $message, int $statusCode = 0, array $responseBody = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}

class BrandforgeConnectionException extends BrandforgeApiException
{
}

class BrandforgeAuthException extends BrandforgeApiException
{
}

class BrandforgeTimeoutException extends BrandforgeApiException
{
}
