<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\App\CacheInterface;

/**
 * A fixed-window request counter, kept in Magento's cache.
 *
 * Used per source address for OAuth registration and authorization - the two
 * endpoints anyone can call without a credential, each writing a row - and per
 * caller for MCP tool calls. It is a brake, not a boundary: the counter is not
 * atomic, so a burst of truly concurrent requests can overshoot by a few.
 */
class Throttle
{
    private const CACHE_PREFIX = 'MAGENX_AIMCP_OAUTH_THROTTLE_';

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Count one hit and say whether it is within the limit.
     *
     * @param string $bucket
     * @param string $remoteAddress
     * @param int $limit
     * @param int $windowSeconds
     * @param int $now
     * @return bool
     */
    public function allow(string $bucket, string $remoteAddress, int $limit, int $windowSeconds, int $now): bool
    {
        return $this->hit($bucket, $remoteAddress, $windowSeconds, $now) <= $limit;
    }

    /**
     * Count one hit and return how many this key has made in the current window, this one included.
     *
     * @param string $bucket
     * @param string $key A source address, or any caller identifier.
     * @param int $windowSeconds
     * @param int $now
     * @return int
     */
    public function hit(string $bucket, string $key, int $windowSeconds, int $now): int
    {
        $window = intdiv($now, $windowSeconds);
        $cacheKey = self::CACHE_PREFIX . strtoupper($bucket) . '_' . hash('sha256', $key) . '_' . $window;
        $count = (int) $this->cache->load($cacheKey) + 1;
        $this->cache->save((string) $count, $cacheKey, [], $windowSeconds);

        return $count;
    }
}
