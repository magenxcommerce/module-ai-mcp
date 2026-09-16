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
    private const XML_PATH_ENABLED_TOOL_DOMAINS = 'magenx_ai_mcp/tools/enabled_domains';
    private const XML_PATH_DISABLED_TOOLS = 'magenx_ai_mcp/tools/disabled_tools';

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
     * Tool domains a client may see. Empty means every domain.
     *
     * **This one getter is not default-deny**, unlike every other on this
     * class. It has to be: an unset value has to keep meaning "all tools", or
     * shipping this setting would silently empty `tools/list` on every existing
     * installation the moment the module is upgraded. The exception is safe
     * because this is not a boundary — ACL and the write switch still decide
     * what a caller may reach; this decides only what is worth showing it.
     *
     * @return string[]
     */
    public function getEnabledToolDomains(): array
    {
        return $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_ENABLED_TOOL_DOMAINS));
    }

    /**
     * Individual tool names withheld from clients, whatever their domain.
     *
     * @return string[]
     */
    public function getDisabledTools(): array
    {
        return $this->splitList((string) $this->scopeConfig->getValue(self::XML_PATH_DISABLED_TOOLS));
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
