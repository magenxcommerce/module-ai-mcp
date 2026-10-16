<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\Throttle;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * @see Throttle
 */
class ThrottleTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    /**
     * @return void
     */
    public function testLimitPerAddressPerWindow(): void
    {
        $throttle = $this->throttle();
        $now = 1_800_000_000;

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($throttle->allow('register', '203.0.113.7', 3, 3600, $now));
        }
        $this->assertFalse($throttle->allow('register', '203.0.113.7', 3, 3600, $now));
        $this->assertTrue($throttle->allow('register', '203.0.113.8', 3, 3600, $now), 'Addresses count apart.');
        $this->assertTrue($throttle->allow('authorize', '203.0.113.7', 3, 3600, $now), 'Buckets count apart.');
        $this->assertTrue($throttle->allow('register', '203.0.113.7', 3, 3600, $now + 3600), 'A new window resets.');
    }

    /**
     * @return Throttle
     */
    private function throttle(): Throttle
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->store[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->store[$key] = $data;

            return true;
        });

        return new Throttle($cache);
    }
}
