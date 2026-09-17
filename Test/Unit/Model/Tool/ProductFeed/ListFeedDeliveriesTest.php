<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\FeedLocator;
use Magenx\AiMcp\Model\Tool\ProductFeed\ListFeedDeliveries;
use Magenx\ProductFeed\Model\Delivery;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\Collection as DeliveryCollection;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reporting a feed's destinations without reporting its credentials.
 *
 * The product feed module encrypts a setting whose key *ends in* password,
 * token, secret or key, and it matches by suffix on purpose: the failure mode
 * of an explicit list is a plaintext credential nobody remembered to add to it.
 * This tool redacts by the same rule for the same reason, so the test that
 * matters uses a key the module has never heard of — if the rule were a
 * hard-coded list, `api_password` would sail straight through into a model's
 * context.
 *
 * @see ListFeedDeliveries::execute
 */
class ListFeedDeliveriesTest extends TestCase
{
    private ListFeedDeliveries $tool;

    /** @var array<int, Delivery> */
    private array $rows = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $collection = $this->createMock(DeliveryCollection::class);
        $collection->method('addFeedFilter')->willReturnSelf();
        $collection->method('addActiveFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(
            fn (): \Traversable => new \ArrayIterator($this->rows)
        );

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->tool = new ListFeedDeliveries($this->locator(), $factory);
    }

    /**
     * @return void
     */
    public function testItIsAReadBehindTheFeedResource(): void
    {
        $this->assertFalse($this->tool->isWrite());
        $this->assertSame('Magenx_ProductFeed::feed', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testEveryKnownCredentialKeyIsWithheld(): void
    {
        $this->rows = [$this->delivery('sftp', [
            'host' => 'feeds.example.test',
            'username' => 'merchant',
            'password' => 'ciphertext',
            'private_key' => 'ciphertext',
            'auth_token' => 'ciphertext',
            'client_secret' => 'ciphertext',
        ])];

        $settings = $this->tool->execute(['feed_id' => 3])['items'][0];

        $this->assertSame(['host' => 'feeds.example.test', 'username' => 'merchant'], $settings['settings']);
        $this->assertSame(
            ['password', 'private_key', 'auth_token', 'client_secret'],
            $settings['withheld_settings']
        );
    }

    /**
     * The suffix rule, proved with a key the module does not ship. A list kept
     * by hand here would drift from theirs, and the direction it drifts in is a
     * credential printed into a transcript.
     *
     * @return void
     */
    public function testAnUnfamiliarCredentialKeyIsStillWithheldBySuffix(): void
    {
        $this->rows = [$this->delivery('ftp', [
            'host' => 'ftp.example.test',
            'api_password' => 'ciphertext',
            'refresh_token' => 'ciphertext',
        ])];

        $item = $this->tool->execute(['feed_id' => 3])['items'][0];

        $this->assertSame(['host' => 'ftp.example.test'], $item['settings']);
        $this->assertContains('api_password', $item['withheld_settings']);
        $this->assertContains('refresh_token', $item['withheld_settings']);
    }

    /**
     * @return void
     */
    public function testWithholdingIsCaseInsensitive(): void
    {
        $this->rows = [$this->delivery('ftp', ['FTP_PASSWORD' => 'ciphertext'])];

        $item = $this->tool->execute(['feed_id' => 3])['items'][0];

        $this->assertSame([], $item['settings']);
        $this->assertSame(['FTP_PASSWORD'], $item['withheld_settings']);
    }

    /**
     * An agent diagnosing "the SFTP delivery fails" needs to know a password is
     * configured at all. An empty settings block with no explanation reads as a
     * destination nobody ever set up.
     *
     * @return void
     */
    public function testWithheldKeysAreReportedByName(): void
    {
        $this->rows = [$this->delivery('sftp', ['password' => 'ciphertext'])];

        $item = $this->tool->execute(['feed_id' => 3])['items'][0];

        $this->assertNotEmpty($item['withheld_settings']);
    }

    /**
     * @return void
     */
    public function testTheLastAttemptIsReported(): void
    {
        $delivery = $this->delivery('google_datasource', []);
        $delivery->setData('last_status', 'error');
        $delivery->setData('last_message', 'Merchant account not linked.');
        $delivery->setData('last_delivered_at', '2026-09-16 04:00:00');
        $this->rows = [$delivery];

        $item = $this->tool->execute(['feed_id' => 3])['items'][0];

        $this->assertSame('error', $item['last_status']);
        $this->assertSame('Merchant account not linked.', $item['last_message']);
    }

    /**
     * A destination that was switched off is often the answer to "why is this
     * feed not arriving", so it must be visible by default.
     *
     * @return void
     */
    public function testInactiveDestinationsAreIncludedByDefault(): void
    {
        $collection = $this->createMock(DeliveryCollection::class);
        $collection->method('addFeedFilter')->willReturnSelf();
        // The assertion: the active filter is not applied unless asked for.
        $collection->expects($this->never())->method('addActiveFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$this->delivery('file', [])]));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $tool = new ListFeedDeliveries($this->locator(), $factory);

        $item = $tool->execute(['feed_id' => 3])['items'][0];

        $this->assertFalse($item['is_active']);
    }

    /**
     * @return void
     */
    public function testActiveOnlyAppliesTheFilter(): void
    {
        $collection = $this->createMock(DeliveryCollection::class);
        $collection->method('addFeedFilter')->willReturnSelf();
        $collection->expects($this->once())->method('addActiveFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $tool = new ListFeedDeliveries($this->locator(), $factory);

        $this->assertSame([], $tool->execute(['feed_id' => 3, 'active_only' => true])['items']);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $config
     * @return Delivery
     */
    private function delivery(string $type, array $config): Delivery
    {
        $delivery = new Delivery();
        $delivery->setData('delivery_id', 1);
        $delivery->setData('type', $type);
        $delivery->setData('config', $config);

        return $delivery;
    }

    /**
     * @return FeedLocator&MockObject
     */
    private function locator(): FeedLocator&MockObject
    {
        $feed = new Feed();
        $feed->setData('feed_id', 3);
        $feed->setData('code', 'google');

        $locator = $this->createMock(FeedLocator::class);
        $locator->method('locate')->willReturn($feed);
        $locator->method('schemaProperties')->willReturn(['feed_id' => ['type' => 'integer']]);

        return $locator;
    }
}
