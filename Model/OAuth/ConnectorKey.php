<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Psr\Log\LoggerInterface;

/**
 * A second secret, sent by the AI service as a header on every MCP call it makes with an OAuth token.
 *
 * claude.ai lets a connector carry a few request headers from a fixed list,
 * and sends them only alongside the bearer token: not on discovery,
 * registration or the token endpoint. So this protects what a token reaches,
 * not the OAuth endpoints. An access token that leaks, or an approval someone
 * talked an admin into from their own Claude account, is refused without it.
 *
 * Generated here, shown once, and stored only as a hash. While no key is set,
 * none is required.
 */
class ConnectorKey
{
    /** On claude.ai's list of headers a connector may send. */
    public const HEADER = 'X-Api-Key';

    /**
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     * @param Secret $secret
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Secret $secret,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * Generate a key, replacing any earlier one, and return it.
     *
     * @param string $adminLabel For the audit log.
     * @return string
     */
    public function generate(string $adminLabel): string
    {
        $key = $this->secret->generate(Secret::PREFIX_CONNECTOR_KEY);
        $this->configWriter->save(OAuthConfig::XML_PATH_CONNECTOR_KEY_HASH, $this->secret->hash($key));
        $this->cacheTypeList->cleanType('config');

        $this->auditLogger->info('[magenx-mcp] oauth connector key generated', ['admin' => $adminLabel]);

        return $key;
    }

    /**
     * Stop requiring a key.
     *
     * @param string $adminLabel For the audit log.
     * @return void
     */
    public function remove(string $adminLabel): void
    {
        $this->configWriter->delete(OAuthConfig::XML_PATH_CONNECTOR_KEY_HASH);
        $this->cacheTypeList->cleanType('config');

        $this->auditLogger->info('[magenx-mcp] oauth connector key removed', ['admin' => $adminLabel]);
    }
}
