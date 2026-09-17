<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\FeedLocator;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedProjector;
use Magenx\AiMcp\Model\Tool\ProductFeed\GenerateFeed;
use Magenx\ProductFeed\Model\Config as FeedConfig;
use Magenx\ProductFeed\Model\Export\RunResult;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\Collection as DeliveryCollection;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory as DeliveryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Running a feed.
 *
 * Two outcomes of this tool are successes that do not look like successes, and
 * one is a side effect that does not look like one. All three are here.
 *
 * A run that used its time budget comes back with `completed` false and
 * `failed` false. That is the module's own definition of a normal tick, and an
 * agent told only "completed: false" would go looking for a fault that does not
 * exist — or, worse, report the feed as published when half of it is still in a
 * work file.
 *
 * And a run that DOES complete is delivered immediately to every active
 * destination, which may be Google Merchant Center or an FTP server. That is
 * not a detail to bury in a note.
 *
 * @see GenerateFeed::execute
 */
class GenerateFeedTest extends TestCase
{
    private FeedLocator&MockObject $locator;
    private FeedManager&MockObject $feedManager;
    private FeedConfig&MockObject $feedConfig;
    private DeliveryCollection&MockObject $deliveries;
    private GenerateFeed $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(FeedLocator::class);
        $this->locator->method('schemaProperties')->willReturn(['feed_id' => ['type' => 'integer']]);
        $this->locator->method('locate')->willReturn($this->feed());

        $this->feedManager = $this->createMock(FeedManager::class);
        $this->feedConfig = $this->createMock(FeedConfig::class);

        $this->deliveries = $this->createMock(DeliveryCollection::class);
        $this->deliveries->method('addFeedFilter')->willReturnSelf();
        $this->deliveries->method('addActiveFilter')->willReturnSelf();
        $this->deliveries->method('getIterator')->willReturn(new \ArrayIterator([]));

        $factory = $this->createMock(DeliveryCollectionFactory::class);
        $factory->method('create')->willReturn($this->deliveries);

        $projector = $this->createMock(FeedProjector::class);
        $projector->method('toDetail')->willReturn(['published' => ['url' => 'https://example.test/f.csv']]);

        $this->tool = new GenerateFeed(
            $this->locator,
            $this->feedManager,
            $this->feedConfig,
            $factory,
            $projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheSeparateGenerateResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        // Deliberately not ::feed — defining a feed and publishing the
        // catalogue outward are different permissions, and the module says so.
        $this->assertSame('Magenx_ProductFeed::generate', $this->tool->getAclResource());
    }

    /**
     * It overwrites what a marketplace holds, and that cannot be undone here.
     *
     * @return void
     */
    public function testItAdvertisesItselfAsDestructive(): void
    {
        $this->assertTrue($this->tool->getAnnotations()['destructiveHint']);
    }

    /**
     * The module answers a disabled store with the same `skipped` result it
     * uses for "already running", distinguishable only by an English string.
     * Checking first means those two never have to be told apart by prose.
     *
     * @return void
     */
    public function testADisabledStoreIsRefusedBeforeTheRunIsEvenAttempted(): void
    {
        $this->feedConfig->method('isEnabled')->willReturn(false);
        $this->feedManager->expects($this->never())->method('process');

        try {
            $this->tool->execute(['feed_id' => 3]);
            $this->fail('A disabled store must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('magenx_product_feed/general/enabled', $e->getMessage());
            $this->assertStringContainsString('set_config', $e->getMessage());
        }
    }

    /**
     * The one that matters most: not an error.
     *
     * @return void
     */
    public function testAPartialRunIsReportedAsASuccessThatWillContinue(): void
    {
        $this->enabled();
        $this->feedManager->method('process')->willReturn(RunResult::progressed(500, 30000));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertSame('partial', $result['outcome']);
        $this->assertFalse($result['delivered']);
        $this->assertSame(500, $result['products_written_so_far']);
        $this->assertStringContainsString('not an error', $result['message']);
        // Cron is what finishes it, so a store whose cron has stopped is one
        // where this never completes.
        $this->assertStringContainsString('cron_status', $result['note']);
    }

    /**
     * @return void
     */
    public function testACompletedRunReportsWhatItPublishedAndWhereItWent(): void
    {
        $this->enabled();
        $this->withActiveDestinations('sftp', 'google_datasource');
        $this->feedManager->method('process')
            ->willReturn(RunResult::finished(1200, 45000, 'magenx-feed/default/abc/f.csv'));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertSame('completed', $result['outcome']);
        $this->assertSame(1200, $result['product_count']);
        $this->assertTrue($result['delivered']);
        $this->assertSame(['sftp', 'google_datasource'], $result['delivered_to']);
    }

    /**
     * @return void
     */
    public function testACompletedRunWithNoDestinationsSaysNothingWasPushed(): void
    {
        $this->enabled();
        $this->feedManager->method('process')->willReturn(RunResult::finished(10, 100, 'p.csv'));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertFalse($result['delivered']);
        $this->assertSame([], $result['delivered_to']);
        $this->assertStringContainsString('nothing was pushed', $result['note']);
    }

    /**
     * Having passed the enabled check, the only skip left is the export lock.
     *
     * @return void
     */
    public function testASkippedRunIsReportedAsAnotherRunAlreadyInProgress(): void
    {
        $this->enabled();
        $this->feedManager->method('process')
            ->willReturn(RunResult::skipped('Another generation of this feed is already running.'));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertSame('skipped', $result['outcome']);
        $this->assertFalse($result['delivered']);
        $this->assertStringContainsString('already running', $result['note']);
        $this->assertStringContainsString('Nothing was changed', $result['note']);
    }

    /**
     * @return void
     */
    public function testAFailedRunIsReportedWithItsMessage(): void
    {
        $this->enabled();
        $this->feedManager->method('process')
            ->willReturn(RunResult::error('Attribute "colour" does not exist.', 900));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertSame('failed', $result['outcome']);
        $this->assertFalse($result['delivered']);
        $this->assertSame('Attribute "colour" does not exist.', $result['message']);
    }

    /**
     * Named whatever the outcome, so the confirm preview an agent sees before
     * approving says where this call would publish to — not merely that it
     * would generate something.
     *
     * @return void
     */
    public function testTheDestinationsAreReportedEvenWhenTheRunDoesNotComplete(): void
    {
        $this->enabled();
        $this->withActiveDestinations('ftp');
        $this->feedManager->method('process')->willReturn(RunResult::progressed(1, 1));

        $result = $this->tool->execute(['feed_id' => 3]);

        $this->assertSame(['ftp'], $result['active_destinations']);
    }

    /**
     * @return void
     */
    private function enabled(): void
    {
        $this->feedConfig->method('isEnabled')->willReturn(true);
    }

    /**
     * @param string ...$types
     * @return void
     */
    private function withActiveDestinations(string ...$types): void
    {
        $deliveries = [];
        foreach ($types as $type) {
            $delivery = new \Magenx\ProductFeed\Model\Delivery();
            $delivery->setData('type', $type);
            $deliveries[] = $delivery;
        }

        $this->deliveries = $this->createMock(DeliveryCollection::class);
        $this->deliveries->method('addFeedFilter')->willReturnSelf();
        $this->deliveries->method('addActiveFilter')->willReturnSelf();
        $this->deliveries->method('getIterator')->willReturn(new \ArrayIterator($deliveries));

        $factory = $this->createMock(DeliveryCollectionFactory::class);
        $factory->method('create')->willReturn($this->deliveries);

        $projector = $this->createMock(FeedProjector::class);
        $projector->method('toDetail')->willReturn(['published' => ['url' => 'https://example.test/f.csv']]);

        $this->tool = new GenerateFeed(
            $this->locator,
            $this->feedManager,
            $this->feedConfig,
            $factory,
            $projector
        );
    }

    /**
     * @return Feed
     */
    private function feed(): Feed
    {
        $feed = new Feed();
        $feed->setData('feed_id', 3);
        $feed->setData('code', 'google');
        $feed->setData('store_id', 1);

        return $feed;
    }
}
