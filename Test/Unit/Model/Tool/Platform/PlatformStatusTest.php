<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Platform;

use Magenx\AiMcp\Model\Tool\Platform\PlatformStatus;
use Magenx\Platform\Model\Collector\CollectorInterface;
use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Probing the services behind the store.
 *
 * Each call reaches out to a live backend, so the thing worth pinning is what
 * the tool refuses to do: probe something the store has not enabled, and turn
 * one misconfigured code into a failed call that costs the reading of every
 * other backend. An agent can call this in a loop far faster than anyone can
 * click Refresh, which is why the enabled list is a gate and not a default.
 *
 * @see PlatformStatus
 */
class PlatformStatusTest extends TestCase
{
    private CollectorPool&MockObject $pool;
    private CollectorRunner&MockObject $runner;
    private Config&MockObject $config;
    private PlatformStatus $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->pool = $this->createMock(CollectorPool::class);
        $this->runner = $this->createMock(CollectorRunner::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getEnabledCollectors')->willReturn(['mariadb', 'redis']);

        $this->tool = new PlatformStatus($this->pool, $this->runner, $this->config);
    }

    /**
     * @return void
     */
    public function testItIsAReadBehindThePlatformModulesOwnResource(): void
    {
        $this->assertFalse($this->tool->isWrite());
        $this->assertSame('Magenx_Platform::platform', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testNoArgumentsProbesEveryEnabledBackend(): void
    {
        $this->pool->method('get')->willReturn($this->createMock(CollectorInterface::class));
        $this->runner->expects($this->exactly(2))->method('run')->willReturn(['status' => 'ok']);

        $result = $this->tool->execute([]);

        $this->assertSame(['mariadb', 'redis'], $result['enabled_collectors']);
        $this->assertCount(2, $result['items']);
    }

    /**
     * A code the store has not enabled is refused with the list of ones it has,
     * rather than probed anyway or silently dropped.
     *
     * @return void
     */
    public function testADisabledCollectorIsRefusedAndTheEnabledOnesAreNamed(): void
    {
        $this->runner->expects($this->never())->method('run');

        try {
            $this->tool->execute(['collectors' => ['rabbitmq']]);
            $this->fail('A collector the store has not enabled must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('rabbitmq', $e->getMessage());
            $this->assertStringContainsString('mariadb, redis', $e->getMessage());
        }
    }

    /**
     * A code left in configuration after its collector was removed costs one
     * "unavailable" entry, not the whole reading.
     *
     * @return void
     */
    public function testAStaleCodeCostsOneEntryRatherThanTheCall(): void
    {
        $this->pool->method('get')->willReturnCallback(
            fn (string $code) => $code === 'redis' ? $this->createMock(CollectorInterface::class) : null
        );
        $this->runner->method('run')->willReturn(['code' => 'redis', 'status' => 'ok']);
        $this->runner->method('unavailable')->willReturn(['status' => 'unavailable', 'summary' => 'gone']);

        $items = $this->tool->execute([])['items'];

        $this->assertCount(2, $items);
        $this->assertSame('mariadb', $items[0]['code']);
        $this->assertSame('unavailable', $items[0]['status']);
        $this->assertSame('ok', $items[1]['status']);
    }

    /**
     * @return void
     */
    public function testTheSameCodeTwiceIsProbedOnce(): void
    {
        $this->pool->method('get')->willReturn($this->createMock(CollectorInterface::class));
        $this->runner->expects($this->once())->method('run')->willReturn(['status' => 'ok']);

        $this->tool->execute(['collectors' => ['redis', 'redis']]);
    }

    /**
     * With the module switched off there is nothing to probe, and saying so
     * beats returning six identical "unavailable" entries.
     *
     * @return void
     */
    public function testAnEntirelyDisabledModuleIsRefusedOnce(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Platform Overview is switched off.');

        (new PlatformStatus($this->pool, $this->runner, $config))->execute([]);
    }

    /**
     * @return void
     */
    public function testAnEntryThatIsNotAStringIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Every entry in "collectors" must be a code string.');

        $this->tool->execute(['collectors' => [7]]);
    }
}
