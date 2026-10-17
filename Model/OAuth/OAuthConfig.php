<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed access to the magenx_ai_mcp/oauth/* store configuration.
 *
 * Kept apart from {@see \Magenx\AiMcp\Model\Config} because none of it applies
 * to the integration-token path: an installation that never turns OAuth on
 * never reads any of this. Default-deny like the rest of the module — OAuth is
 * off, dynamic registration is off, loopback redirects are off, and an empty
 * redirect allowlist registers no client at all.
 */
class OAuthConfig
{
    private const XML_PATH_ENABLED = 'magenx_ai_mcp/oauth/enabled';
    private const XML_PATH_DYNAMIC_REGISTRATION = 'magenx_ai_mcp/oauth/allow_dynamic_registration';
    private const XML_PATH_REDIRECT_URIS = 'magenx_ai_mcp/oauth/allowed_redirect_uris';
    private const XML_PATH_ALLOW_LOOPBACK = 'magenx_ai_mcp/oauth/allow_loopback_redirects';
    private const XML_PATH_ACCESS_LIFETIME = 'magenx_ai_mcp/oauth/access_token_lifetime';
    private const XML_PATH_REFRESH_LIFETIME = 'magenx_ai_mcp/oauth/refresh_token_lifetime';
    private const XML_PATH_PUBLIC_BASE_URL = 'magenx_ai_mcp/oauth/public_base_url';
    public const XML_PATH_CONNECTOR_KEY_HASH = 'magenx_ai_mcp/oauth/connector_key_hash';

    /** Bounds on the configurable lifetimes, so a typo cannot mint a token for a year. */
    private const ACCESS_MINUTES_MIN = 5;
    private const ACCESS_MINUTES_MAX = 1440;
    private const ACCESS_MINUTES_DEFAULT = 60;
    private const REFRESH_DAYS_MIN = 1;
    private const REFRESH_DAYS_MAX = 90;
    private const REFRESH_DAYS_DEFAULT = 30;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the OAuth endpoints answer and OAuth access tokens are accepted.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Whether anyone may register a client (RFC 7591) and start a flow with it.
     *
     * Off, only clients an administrator created can be sent to the consent
     * page. A registered client carries no privilege, but letting anyone start
     * a flow lets anyone learn the admin URL from the redirect, and hand an
     * admin a consent link that would connect the sender's own Claude account.
     *
     * @return bool
     */
    public function isDynamicRegistrationAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DYNAMIC_REGISTRATION);
    }

    /**
     * Redirect URIs a client may register, compared exactly.
     *
     * @return string[]
     */
    public function getAllowedRedirectUris(): array
    {
        $parts = preg_split('/[\s,]+/', (string) $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URIS)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Whether http://localhost, http://127.0.0.1 and http://[::1] callbacks on any port are accepted.
     *
     * @return bool
     */
    public function isLoopbackAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ALLOW_LOOPBACK);
    }

    /**
     * Access token lifetime in seconds.
     *
     * @return int
     */
    public function getAccessTokenLifetime(): int
    {
        return 60 * $this->bounded(
            self::XML_PATH_ACCESS_LIFETIME,
            self::ACCESS_MINUTES_MIN,
            self::ACCESS_MINUTES_MAX,
            self::ACCESS_MINUTES_DEFAULT
        );
    }

    /**
     * Absolute lifetime of a grant, in seconds, counted from the admin's approval.
     *
     * Refreshing never extends it: once it runs out the admin has to sign in
     * again, which is what puts two-factor authentication back in the path.
     *
     * @return int
     */
    public function getRefreshTokenLifetime(): int
    {
        return 86400 * $this->bounded(
            self::XML_PATH_REFRESH_LIFETIME,
            self::REFRESH_DAYS_MIN,
            self::REFRESH_DAYS_MAX,
            self::REFRESH_DAYS_DEFAULT
        );
    }

    /**
     * The origin clients reach this store on, when it differs from the store's own base URL.
     *
     * @return string Empty when not set.
     */
    public function getPublicBaseUrl(): string
    {
        return rtrim(trim((string) $this->scopeConfig->getValue(self::XML_PATH_PUBLIC_BASE_URL)), '/');
    }

    /**
     * SHA-256 of the connector key every OAuth-authenticated MCP call must carry.
     *
     * Set and cleared only by {@see ConnectorKey}; never a value an admin types.
     *
     * @return string Empty when no key is required.
     */
    public function getConnectorKeyHash(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONNECTOR_KEY_HASH));
    }

    /**
     * Read an integer setting, falling back to the default when unset or out of range.
     *
     * @param string $path
     * @param int $min
     * @param int $max
     * @param int $default
     * @return int
     */
    private function bounded(string $path, int $min, int $max, int $default): int
    {
        $raw = trim((string) $this->scopeConfig->getValue($path));
        if ($raw === '' || !ctype_digit($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return $value < $min || $value > $max ? $default : $value;
    }
}
