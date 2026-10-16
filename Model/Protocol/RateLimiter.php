<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Protocol;

use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\Throttle;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Psr\Log\LoggerInterface;

/**
 * Per-caller limits on tool calls, so an agent stuck in a loop cannot hammer the store.
 *
 * Counted per caller - one integration, or one admin across every OAuth
 * connection they approved - in one-minute windows: all tool calls against
 * magenx_ai_mcp/security/rate_limit, and confirmed writes additionally against
 * write_rate_limit, because a looping write does damage a looping read does
 * not. A refusal is a message the agent reads and can wait on, not a protocol
 * error, and only the first refusal in a window is logged so a loop cannot
 * flood the audit file either.
 */
class RateLimiter
{
    private const WINDOW = 60;

    /**
     * @param Config $config
     * @param Throttle $throttle
     * @param CoreDate $date
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly Config $config,
        private readonly Throttle $throttle,
        private readonly CoreDate $date,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * Count a tool call; return the refusal to send, or null to go ahead.
     *
     * @param Identity $identity
     * @return string|null
     */
    public function checkCall(Identity $identity): ?string
    {
        return $this->check('call', $identity, $this->config->getRateLimit(), 'tool calls');
    }

    /**
     * Count a confirmed write; return the refusal to send, or null to go ahead.
     *
     * @param Identity $identity
     * @return string|null
     */
    public function checkWrite(Identity $identity): ?string
    {
        return $this->check('write', $identity, $this->config->getWriteRateLimit(), 'changes');
    }

    /**
     * @param string $bucket
     * @param Identity $identity
     * @param int $limit
     * @param string $what
     * @return string|null
     */
    private function check(string $bucket, Identity $identity, int $limit, string $what): ?string
    {
        if ($limit <= 0) {
            return null;
        }

        $now = (int) $this->date->gmtTimestamp();
        $count = $this->throttle->hit(
            'mcp_' . $bucket,
            $identity->getUserType() . ':' . $identity->getUserId(),
            self::WINDOW,
            $now
        );
        if ($count <= $limit) {
            return null;
        }

        if ($count === $limit + 1) {
            $this->auditLogger->warning('[magenx-mcp] rate limit reached', [
                'caller' => $identity->getLabel(),
                'user_type' => $identity->getUserType(),
                'user_id' => $identity->getUserId(),
                'limit' => $bucket . ':' . $limit . '/min',
            ]);
        }

        return sprintf(
            'Rate limit reached: at most %d %s per minute for this connection. Wait %d seconds before trying again, '
            . 'and do not repeat calls that already succeeded.',
            $limit,
            $what,
            self::WINDOW - ($now % self::WINDOW)
        );
    }
}
