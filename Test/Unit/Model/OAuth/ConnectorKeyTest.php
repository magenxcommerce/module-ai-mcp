<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\ConnectorKey;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\Secret;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The key is handed out once and kept only as a hash.
 *
 * @see ConnectorKey
 */
class ConnectorKeyTest extends TestCase
{
    /**
     * @return void
     */
    public function testGenerateStoresOnlyTheHashAndRefreshesConfig(): void
    {
        $saved = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            static function ($path, $value) use (&$saved): void {
                $saved[$path] = $value;
            }
        );
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($this->once())->method('cleanType')->with('config');

        $key = (new ConnectorKey($writer, $cache, new Secret(), $this->createMock(LoggerInterface::class)))
            ->generate('admin:alice');

        $this->assertStringStartsWith('mxk_', $key);
        $this->assertSame([OAuthConfig::XML_PATH_CONNECTOR_KEY_HASH => hash('sha256', $key)], $saved);
    }

    /**
     * @return void
     */
    public function testRemoveDeletesTheHash(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('delete')->with(OAuthConfig::XML_PATH_CONNECTOR_KEY_HASH);
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($this->once())->method('cleanType')->with('config');

        (new ConnectorKey($writer, $cache, new Secret(), $this->createMock(LoggerInterface::class)))
            ->remove('admin:alice');
    }
}
