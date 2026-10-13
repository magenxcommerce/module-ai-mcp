<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\EnvironmentVersions;
use Magenx\Platform\Model\Collector\CollectorInterface;
use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config as PlatformConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Reading service versions without opening a connection of its own.
 *
 * @see EnvironmentVersions
 */
class EnvironmentVersionsTest extends TestCase
{
    /**
     * @return void
     */
    public function testItReadsTheDatabaseDirectlyAndTheRestFromEnabledCollectors(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->with('SELECT VERSION()')->willReturn('11.4.10-MariaDB-ubu2404');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $config = $this->createMock(PlatformConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getEnabledCollectors')->willReturn(['mariadb', 'opensearch', 'redis']);

        $pool = $this->createMock(CollectorPool::class);
        $pool->method('get')->willReturn($this->createMock(CollectorInterface::class));

        $runner = $this->createMock(CollectorRunner::class);
        $runner->expects($this->exactly(2))->method('run')->willReturnCallback(
            static fn (string $code): array => ['sections' => [[
                'label' => 'Server',
                'rows' => [
                    ['label' => 'Endpoint', 'value' => 'somewhere'],
                    ['label' => 'Version', 'value' => $code === 'opensearch' ? 'Opensearch 2.19.5' : '8.6.1'],
                ],
            ]]]
        );

        $versions = (new EnvironmentVersions($resource, $config, $pool, $runner))->get();
        $measured = array_column($versions['measured'], null, 'component');

        $this->assertSame(PHP_VERSION, $measured['php']['version']);
        $this->assertSame('mariadb', $measured['database']['product']);
        $this->assertSame('opensearch', $measured['search']['product']);
        $this->assertSame('Opensearch 2.19.5', $measured['search']['version']);
        $this->assertSame('8.6.1', $measured['cache']['version']);
        $this->assertSame(['queue'], array_column($versions['unmeasured'], 'component'));
        $this->assertStringContainsString('"rabbitmq" collector is not enabled', $versions['unmeasured'][0]['reason']);
    }
}
