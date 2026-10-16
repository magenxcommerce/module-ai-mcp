<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\App\CacheInterface;

/**
 * A fixed-window request counter per source address, kept in Magento's cache.
 *
 * Registration and authorization are the two OAuth endpoints anyone can call
 * without a credential, and each writes a row. This caps how fast one address
 * can make them do so. It is a brake, not a boundary: the counter is not
 * atomic, and the edge allowlist in nginx remains the real gate.
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
        $window = intdiv($now, $windowSeconds);
        $key = self::CACHE_PREFIX . strtoupper($bucket) . '_' . hash('sha256', $remoteAddress) . '_' . $window;
        $count = (int) $this->cache->load($key) + 1;
        $this->cache->save((string) $count, $key, [], $windowSeconds);

        return $count <= $limit;
    }
}
