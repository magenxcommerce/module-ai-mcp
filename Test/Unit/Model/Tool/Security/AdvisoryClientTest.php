<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Tool\Security\AdvisoryClient;
use Magenx\Platform\Model\Http\JsonFetcher;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The one outbound request these tools make.
 *
 * What is pinned is what it refuses: to run while switched off, to speak
 * anything but https, and to put a caller's string into the request path
 * unless it is shaped like a release. And that an agent calling in a loop is
 * answered from cache rather than by the service.
 *
 * @see AdvisoryClient
 */
class AdvisoryClientTest extends TestCase
{
    private Config&MockObject $config;
    private JsonFetcher&MockObject $fetcher;
    private CacheInterface&MockObject $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isAdvisoryLookupEnabled')->willReturn(true);
        $this->fetcher = $this->createMock(JsonFetcher::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    /**
     * @return void
     */
    public function testItFetchesTheReleaseAndKeepsItForAnHour(): void
    {
        $this->config->method('getAdvisoryApiUrl')->willReturn('https://magento.watch/api/v1/');
        $this->fetcher->expects($this->once())
            ->method('fetch')
            ->with('https://magento.watch/api/v1/magento-community/versions/2.4.8-p5')
            ->willReturn(['data' => ['version' => '2.4.8-p5']]);
        $this->cache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->stringStartsWith('magenx_ai_mcp_advisory_'), [], 3600);

        $result = $this->client()->getVersion('magento-community', '2.4.8-p5');

        $this->assertSame(['version' => '2.4.8-p5'], $result['data']);
        $this->assertFalse($result['cached']);
    }

    /**
     * @return void
     */
    public function testACachedAnswerIsNotFetchedAgain(): void
    {
        $this->config->method('getAdvisoryApiUrl')->willReturn('https://magento.watch/api/v1');
        $this->cache->method('load')->willReturn(json_encode([
            'data' => ['version' => '2.4.8-p5'],
            'fetched_at' => '2026-10-13 10:00:00 UTC',
        ]));
        $this->fetcher->expects($this->never())->method('fetch');

        $result = $this->client()->getVersion('magento-community', '2.4.8-p5');

        $this->assertTrue($result['cached']);
        $this->assertSame('2026-10-13 10:00:00 UTC', $result['fetched_at']);
    }

    /**
     * @return void
     */
    public function testSwitchedOffItSendsNothingAndSaysWhere(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isAdvisoryLookupEnabled')->willReturn(false);
        $this->fetcher->expects($this->never())->method('fetch');

        $client = new AdvisoryClient($config, $this->fetcher, $this->cache, new Json());

        $this->assertNull($client->getVersion('magento-community', '2.4.8-p5'));
        $this->assertStringContainsString('Security Advisories', $client->getLastError());
    }

    /**
     * Whether a store is reported as patched rests on this answer, so it is
     * not taken over plain HTTP.
     *
     * @return void
     */
    public function testPlainHttpIsRefused(): void
    {
        $this->config->method('getAdvisoryApiUrl')->willReturn('http://magento.watch/api/v1');
        $this->fetcher->expects($this->never())->method('fetch');

        $client = $this->client();

        $this->assertNull($client->getSupportedVersions('magento-community'));
        $this->assertStringContainsString('https://', $client->getLastError());
    }

    /**
     * @return void
     */
    public function testAFailedRequestIsExplainedAndNotCached(): void
    {
        $this->config->method('getAdvisoryApiUrl')->willReturn('https://magento.watch/api/v1');
        $this->fetcher->method('fetch')->willReturn(null);
        $this->fetcher->method('getLastError')->willReturn('HTTP 404 from https://magento.watch/api/v1/x');
        $this->cache->expects($this->never())->method('save');

        $client = $this->client();

        $this->assertNull($client->getVersion('magento-community', '9.9.9'));
        $this->assertStringContainsString('HTTP 404', $client->getLastError());
    }

    /**
     * @return void
     */
    public function testAStringThatIsNotAReleaseNeverReachesThePath(): void
    {
        $this->fetcher->expects($this->never())->method('fetch');

        $this->expectException(LocalizedException::class);

        $this->client()->getVersion('magento-community', '2.4.8/../../patches');
    }

    /**
     * @return void
     */
    public function testAnUnknownDistributionIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->client()->getSupportedVersions('shopware');
    }

    /**
     * @return AdvisoryClient
     */
    private function client(): AdvisoryClient
    {
        return new AdvisoryClient($this->config, $this->fetcher, $this->cache, new Json());
    }
}
