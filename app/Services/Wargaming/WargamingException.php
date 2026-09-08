<?php

namespace App\Services\Wargaming;

use RuntimeException;

/**
 * Raised when the Wargaming API answers with its error envelope, or can't be
 * reached at all.
 *
 * The API returns HTTP 200 for application-level failures and puts the real
 * outcome in the body, so a non-exception response is not the same as success.
 */
class WargamingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $apiCode = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The application ID is registered as a Server type and this machine's IP
     * isn't on its allow-list. Distinct because the fix is a config change in
     * the Developer Room, not anything in this codebase.
     */
    public function isInvalidIpAddress(): bool
    {
        return $this->apiCode === 'INVALID_IP_ADDRESS';
    }

    /**
     * The access token has expired or been revoked — the account link is still
     * good, the credential isn't.
     */
    public function isInvalidAccessToken(): bool
    {
        return in_array($this->apiCode, ['INVALID_ACCESS_TOKEN', 'ACCESS_TOKEN_EXPIRED'], true);
    }
}
