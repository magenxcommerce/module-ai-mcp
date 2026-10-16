<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Protocol;

use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\Throttle;
use Magenx\AiMcp\Model\Protocol\RateLimiter;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see RateLimiter
 */
class RateLimiterTest extends TestCase
{
    /** @var array<string, string> */
    private array $cache = [];

    private int $now = 1_800_000_010;

    /**
     * @return void
     */
    public function testCallsPastTheLimitAreRefusedWithAWaitTime(): void
    {
        $limiter = $this->limiter(3, 0);
        $caller = $this->identity(1);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($limiter->checkCall($caller));
        }
        $refusal = (string) $limiter->checkCall($caller);

        $this->assertStringContainsString('at most 3 tool calls per minute', $refusal);
        $this->assertStringContainsString('Wait 50 seconds', $refusal);
    }

    /**
     * @return void
     */
    public function testCallersAreCountedApart(): void
    {
        $limiter = $this->limiter(1, 0);

        $this->assertNull($limiter->checkCall($this->identity(1)));
        $this->assertNull($limiter->checkCall($this->identity(2)));
        $this->assertNotNull($limiter->checkCall($this->identity(1)));
    }

    /**
     * @return void
     */
    public function testANewMinuteStartsAfresh(): void
    {
        $limiter = $this->limiter(1, 0);
        $caller = $this->identity(1);
        $limiter->checkCall($caller);

        $this->now += 60;

        $this->assertNull($limiter->checkCall($caller));
    }

    /**
     * @return void
     */
    public function testWritesHaveTheirOwnLimit(): void
    {
        $limiter = $this->limiter(0, 1);
        $caller = $this->identity(1);

        $this->assertNull($limiter->checkCall($caller), 'A zero call limit means no call limit.');
        $this->assertNull($limiter->checkWrite($caller));
        $this->assertStringContainsString('1 changes per minute', (string) $limiter->checkWrite($caller));
    }

    /**
     * A loop must not flood the audit log: only the first refusal of a window is logged.
     *
     * @return void
     */
    public function testOnlyTheFirstRefusalIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $limiter = $this->limiter(1, 0, $logger);
        $caller = $this->identity(1);

        for ($i = 0; $i < 5; $i++) {
            $limiter->checkCall($caller);
        }
    }

    /**
     * @param int $calls
     * @param int $writes
     * @param LoggerInterface|null $logger
     * @return RateLimiter
     */
    private function limiter(int $calls, int $writes, ?LoggerInterface $logger = null): RateLimiter
    {
        $config = $this->createMock(Config::class);
        $config->method('getRateLimit')->willReturn($calls);
        $config->method('getWriteRateLimit')->willReturn($writes);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cache[$key] = $data;

            return true;
        });

        $date = $this->createMock(CoreDate::class);
        $date->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new RateLimiter(
            $config,
            new Throttle($cache),
            $date,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param int $userId
     * @return Identity
     */
    private function identity(int $userId): Identity
    {
        return new Identity(2, $userId, 'admin#' . $userId, []);
    }
}
