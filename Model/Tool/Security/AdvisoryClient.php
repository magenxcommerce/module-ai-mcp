<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magenx\AiMcp\Model\Config;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Release, bulletin and requirement data from the magento.watch version API.
 *
 * magento.watch is an independent, community-run index of Adobe's security
 * bulletins and system requirements, keyed by release — the one place that
 * answers "which bulletins are still open against 2.4.8-p5, and which isolated
 * patch closes each" without scraping Adobe's pages. It needs no key.
 *
 * What it is sent is the release string in the request path, from this
 * server's address. That is the whole disclosure, and the reason the lookup
 * has a switch of its own.
 *
 * Responses are kept for an hour, which is what the API itself advertises in
 * Cache-Control: an agent calling the tool in a loop asks this cache, not the
 * service. A failed request is not kept, so the next call retries.
 *
 * The request goes out through the Platform module's fetcher, which already
 * bounds every outbound call by a timeout, refuses redirects and accepts only
 * http(s). This class narrows that to https: what comes back decides whether a
 * store is reported as patched, so it is not something to take over plain HTTP.
 */
class AdvisoryClient
{
    /** The distributions the API indexes, as it spells them. */
    public const DISTRIBUTIONS = ['magento-community', 'magento-commerce', 'mage-os'];

    private const CACHE_PREFIX = 'magenx_ai_mcp_advisory_';
    private const CACHE_TTL = 3600;

    private string $lastError = '';

    /**
     * @param Config $config
     * @param JsonFetcher $fetcher
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        private readonly Config $config,
        private readonly JsonFetcher $fetcher,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * Whether the lookup is switched on at all.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isAdvisoryLookupEnabled();
    }

    /**
     * One release: lifecycle dates, system requirements and its `security`
     * block — open bulletins with their isolated patches, and the bulletins
     * the release itself fixed.
     *
     * @param string $distribution
     * @param string $version
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     * @throws LocalizedException When the distribution or version is malformed.
     */
    public function getVersion(string $distribution, string $version): ?array
    {
        return $this->get($this->assertDistribution($distribution) . '/versions/' . $this->assertVersion($version));
    }

    /**
     * Every release still in support, keyed by version, each with its
     * requirements and a `securitySummary`.
     *
     * @param string $distribution
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     * @throws LocalizedException When the distribution is not one the API indexes.
     */
    public function getSupportedVersions(string $distribution): ?array
    {
        return $this->get($this->assertDistribution($distribution) . '/versions/supported');
    }

    /**
     * Why the last lookup returned null.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * @param string $path
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}|null
     */
    private function get(string $path): ?array
    {
        $this->lastError = '';

        if (!$this->isEnabled()) {
            $this->lastError = 'The advisory lookup is switched off under Stores > Configuration > Magenx > '
                . 'AI MCP Server > Security Advisories.';

            return null;
        }

        $base = rtrim($this->config->getAdvisoryApiUrl(), '/');
        if (preg_match('#^https://[^\s/]+#i', $base) !== 1) {
            $this->lastError = 'The advisory API URL must be an https:// URL.';

            return null;
        }

        $url = $base . '/' . $path;
        $key = self::CACHE_PREFIX . hash('sha256', $url);

        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            try {
                $payload = $this->json->unserialize($cached);
            } catch (\InvalidArgumentException) {
                $payload = null;
            }
            if (is_array($payload) && is_array($payload['data'] ?? null)) {
                return ['data' => $payload['data'], 'fetched_at' => (string) $payload['fetched_at'], 'cached' => true];
            }
        }

        $response = $this->fetcher->fetch($url);
        if ($response === null) {
            $this->lastError = sprintf('magento.watch did not answer: %s', $this->fetcher->getLastError());

            return null;
        }
        if (!is_array($response['data'] ?? null)) {
            $this->lastError = is_string($response['error'] ?? null)
                ? sprintf('magento.watch answered "%s" for %s', $response['error'], $path)
                : 'magento.watch answered without a data object.';

            return null;
        }

        $payload = ['data' => $response['data'], 'fetched_at' => gmdate('Y-m-d H:i:s') . ' UTC'];
        $this->cache->save($this->json->serialize($payload), $key, [], self::CACHE_TTL);

        return $payload + ['cached' => false];
    }

    /**
     * @param string $distribution
     * @return string
     * @throws LocalizedException
     */
    private function assertDistribution(string $distribution): string
    {
        if (!in_array($distribution, self::DISTRIBUTIONS, true)) {
            throw new LocalizedException(__(
                'Unknown distribution "%1". Use one of: %2.',
                $distribution,
                implode(', ', self::DISTRIBUTIONS)
            ));
        }

        return $distribution;
    }

    /**
     * A release string safe to put in a URL path: digits first, then only the
     * characters release names use. Anything else would be a path the API
     * never published, or one that leaves the versions resource entirely.
     *
     * @param string $version
     * @return string
     * @throws LocalizedException
     */
    private function assertVersion(string $version): string
    {
        if (preg_match('/^\d[0-9A-Za-z.\-]{0,39}$/', $version) !== 1 || str_contains($version, '..')) {
            throw new LocalizedException(__('"%1" is not a release version such as 2.4.8-p5.', $version));
        }

        return $version;
    }
}
