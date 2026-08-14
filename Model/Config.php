<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed access to the magenx_ai_mcp/* store configuration.
 *
 * Every getter is deliberately default-deny: an unset value reads as "off" or
 * "nothing permitted", never as "allow".
 */
class Config
{
    private const XML_PATH_ENABLED = 'magenx_ai_mcp/general/enabled';
    private const XML_PATH_ALLOW_WRITES = 'magenx_ai_mcp/security/allow_writes';
    private const XML_PATH_ALLOWED_IPS = 'magenx_ai_mcp/security/allowed_ips';
    private const XML_PATH_ALLOWED_CONFIG_PATHS = 'magenx_ai_mcp/security/allowed_config_paths';
    private const XML_PATH_ALLOWED_ORIGINS = 'magenx_ai_mcp/security/allowed_origins';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the MCP endpoint answers at all.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Whether tools that change data may be listed and called.
     *
     * @return bool
     */
    public function isWriteAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ALLOW_WRITES);
    }

    /**
     * Source addresses permitted to reach the endpoint. Empty means no
     * restriction (the token is then the only gate).
     *
     * @return string[]
     */
    public function getAllowedIps(): array
    {
        return $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_IPS));
    }

    /**
     * Glob patterns of configuration paths that may be written. Empty denies
     * every write.
     *
     * @return string[]
     */
    public function getAllowedConfigPaths(): array
    {
        return $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_CONFIG_PATHS));
    }

    /**
     * Browser origins permitted to call the endpoint. Empty denies every
     * request that carries an Origin header at all — which is every browser,
     * and no ordinary MCP client.
     *
     * @return string[]
     */
    public function getAllowedOrigins(): array
    {
        return $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_ORIGINS));
    }

    /**
     * Split a comma/newline separated admin textarea into trimmed entries.
     *
     * @param string $raw
     * @return string[]
     */
    private function splitList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
