<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * Generates and hashes the bearer values this authorization server hands out.
 *
 * Every value carries a short prefix naming what it is, so a token pasted in
 * the wrong place is refused for the right reason, and so the MCP endpoint can
 * tell an OAuth access token from a Magento integration token without a
 * database lookup. Only SHA-256 hashes are stored: a copy of the tables is not
 * a copy of anyone's access. A plain hash rather than a slow one is correct
 * here — the values are 256 random bits, so there is nothing to brute-force.
 */
class Secret
{
    public const PREFIX_ACCESS = 'mxa_';
    public const PREFIX_REFRESH = 'mxr_';
    public const PREFIX_CODE = 'mxc_';
    public const PREFIX_CLIENT = 'mxclient_';
    public const PREFIX_CLIENT_SECRET = 'mxs_';

    /**
     * A new random value with the given prefix.
     *
     * @param string $prefix
     * @return string
     */
    public function generate(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * A random request handle: not a credential on its own, so hex is enough.
     *
     * @return string
     */
    public function handle(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The stored form of a value.
     *
     * @param string $value
     * @return string
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
