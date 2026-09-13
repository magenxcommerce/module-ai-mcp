<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\Ops\SetCacheState;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Switching cache types on and off.
 *
 * Disabling the configuration cache on a live store is not a slow store, it is
 * an outage — and the tool that did it would be the first thing unable to
 * re-enable it. That one is refused outright; everything else is allowed but
 * has to name a type this installation actually has.
 *
 * @see SetCacheState
 */
class SetCacheStateTest extends TestCase
{
    private StateInterface&MockObject $cacheState;
    private SetCacheState $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->cacheState = $this->createMock(StateInterface::class);

        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([
            'config' => new DataObject(),
            'block_html' => new DataObject(),
            'full_page' => new DataObject(),
        ]);

        $this->tool = new SetCacheState($this->cacheState, $typeList);
    }

    /**
     * @return void
     */
    public function testItIsAWriteEvenThoughNoDataChanges(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Backend::cache', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testTheConfigurationCacheCannotBeDisabled(): void
    {
        $this->cacheState->expects($this->never())->method('setEnabled');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The config cache cannot be disabled through this server');
        $this->tool->execute(['types' => ['block_html', 'config'], 'enabled' => false]);
    }

    /**
     * Enabling it is always safe, so the guard must not be a blanket ban on the
     * type.
     *
     * @return void
     */
    public function testTheConfigurationCacheCanStillBeEnabled(): void
    {
        $this->cacheState->expects($this->once())->method('setEnabled')->with('config', true);
        $this->cacheState->expects($this->once())->method('persist');

        $result = $this->tool->execute(['types' => ['config'], 'enabled' => true]);

        $this->assertTrue($result['enabled']);
        $this->assertSame(['config'], $result['types']);
    }

    /**
     * @return void
     */
    public function testAnUnknownTypeIsRefusedWithTheValidOnes(): void
    {
        $this->cacheState->expects($this->never())->method('setEnabled');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Unknown cache types: layout. Available types are: config, block_html, full_page.'
        );
        $this->tool->execute(['types' => ['layout'], 'enabled' => false]);
    }

    /**
     * A string "false" is what a model actually produces, and casting it would
     * enable the cache the caller meant to switch off.
     *
     * @return void
     */
    public function testEnabledMustBeARealBoolean(): void
    {
        $this->cacheState->expects($this->never())->method('setEnabled');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "enabled" argument must be true or false');
        $this->tool->execute(['types' => ['block_html'], 'enabled' => 'false']);
    }

    /**
     * @return void
     */
    public function testNoTypesIsRefusedRatherThanTreatedAsAll(): void
    {
        $this->cacheState->expects($this->never())->method('setEnabled');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pass at least one cache type in "types".');
        $this->tool->execute(['types' => [], 'enabled' => false]);
    }
}
