<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model;

/**
 * Decides which configuration paths may be read in full and which may be
 * written.
 *
 * Two independent gates, in this order:
 *
 *  1. A hard-coded denylist of secret-bearing paths. It is not configurable,
 *     because the failure it prevents — an agent reading or overwriting a
 *     payment key, the encryption key, or an admin credential — is not a
 *     trade-off any store should be able to opt into through a text field.
 *  2. The store's own allowlist, which is empty by default and therefore
 *     denies every write until an operator names the paths they want managed.
 *
 * Reads are permitted broadly (configuration is what the agent is here to
 * inspect) but secret values are redacted rather than returned.
 */
class ConfigPathPolicy
{
    /**
     * Substrings that mark a path as secret-bearing, matched case-insensitively
     * against the whole path.
     */
    private const SECRET_MARKERS = [
        'password',
        'secret',
        'private_key',
        'encryption_key',
        'api_key',
        'apikey',
        'access_key',
        'access_token',
        'token',
        'credential',
        'signature',
        'salt',
        'merchant_id',
        'client_secret',
    ];

    /**
     * Path prefixes that may never be written, whatever the allowlist says.
     */
    private const PROTECTED_PREFIXES = [
        'admin/',
        'oauth/',
        'crypt/',
        'system/security/',
        'magenx_ai_mcp/',
    ];

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Whether a value at this path must be hidden when read.
     *
     * @param string $path
     * @return bool
     */
    public function isSecret(string $path): bool
    {
        $needle = strtolower($path);
        foreach (self::SECRET_MARKERS as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stored value is one of Magento's encrypted blobs.
     *
     * @param string|null $value
     * @return bool
     */
    public function looksEncrypted(?string $value): bool
    {
        return $value !== null && preg_match('/^\d+:\d+:/', $value) === 1;
    }

    /**
     * Whether this path may be written. Returns a reason when it may not, so
     * the agent is told which gate refused it.
     *
     * @param string $path
     * @return string|null Null when the write is permitted.
     */
    public function refuseWriteReason(string $path): ?string
    {
        if ($this->isSecret($path)) {
            return sprintf(
                'The path "%s" looks like it holds a credential. Secret-bearing paths cannot be '
                . 'written through this server; change it in the Magento admin instead.',
                $path
            );
        }

        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return sprintf('The path "%s" is protected and cannot be written through this server.', $path);
            }
        }

        $allowed = $this->config->getAllowedConfigPaths();
        if ($allowed === []) {
            return 'No configuration paths are writable. An administrator must list them under '
                . 'Stores > Configuration > Magenx > AI MCP Server > Writable Configuration Paths.';
        }

        foreach ($allowed as $pattern) {
            if (fnmatch($pattern, $path)) {
                return null;
            }
        }

        return sprintf(
            'The path "%s" is not in the store\'s writable configuration paths.',
            $path
        );
    }
}
