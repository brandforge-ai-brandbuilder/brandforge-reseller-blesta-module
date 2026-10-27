<?php
/**
 * Exception type for BrandforgeSyncClient failures.
 *
 * Deliberately a SEPARATE class from the module's BrandforgeApiException
 * (module/brandforge/apis/brandforge_exceptions.php) — Blesta installs
 * modules under components/modules/ and plugins under plugins/, with no
 * documented supported path for one extension to require() the other's
 * files, so this plugin ships its own small, independent copy rather than
 * attempt a cross-extension require (unlike WHMCS, where the addon module
 * already requires across modules/servers/ and modules/addons/ because
 * both live as siblings in the same zip).
 */
class BrandforgeSyncApiException extends RuntimeException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
