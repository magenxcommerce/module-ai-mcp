<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\CreateFeed;
use Magenx\AiMcp\Model\Tool\ProductFeed\DeleteFeed;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedArguments;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedLocator;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedProjector;
use Magenx\AiMcp\Model\Tool\ProductFeed\GetFeed;
use Magenx\AiMcp\Model\Tool\ProductFeed\GetFeedHistory;
use Magenx\AiMcp\Model\Tool\ProductFeed\SearchFeeds;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory as FeedCollectionFactory;
use Magenx\ProductFeed\Model\ResourceModel\History\Collection as HistoryCollection;
use Magenx\ProductFeed\Model\ResourceModel\History\CollectionFactory as HistoryCollectionFactory;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The contract each remaining feed tool advertises.
 *
 * The behaviour of the four interesting tools is pinned in their own files.
 * What is left is the surface every tool in this module is expected to get
 * right — which grant it sits behind, whether it writes, and a closed schema —
 * plus the two places where the feed tools deviate from what a reader would
 * assume, and where a future edit would most plausibly "tidy" the deviation
 * away.
 */
class FeedToolContractTest extends TestCase
{
    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        GeneratedFactory::ensure(FeedFactory::class);
    }

    /**
     * @return void
     */
    public function testTheReadsAreReadsBehindTheFeedResource(): void
    {
        foreach ([$this->searchFeeds(), $this->getFeed(), $this->getFeedHistory()] as $tool) {
            $this->assertFalse($tool->isWrite(), $tool->getName());
            $this->assertSame('Magenx_ProductFeed::feed', $tool->getAclResource(), $tool->getName());
            $this->assertFalse($tool->getInputSchema()['additionalProperties'], $tool->getName());
        }
    }

    /**
     * @return void
     */
    public function testCreateIsAWriteThatOnlyAdds(): void
    {
        $tool = $this->createFeed();

        $this->assertTrue($tool->isWrite());
        $this->assertSame('Magenx_ProductFeed::feed', $tool->getAclResource());
        // It defines a feed and publishes nothing until generate_feed runs.
        $this->assertFalse($tool->getAnnotations()['destructiveHint']);
    }

    /**
     * @return void
     */
    public function testCreateRequiresTheFourFieldsTheAdminFormDoesButTheModelDoesNot(): void
    {
        $schema = $this->createFeed()->getInputSchema();

        $this->assertSame(['name', 'store_id', 'format', 'filename'], $schema['required']);
    }

    /**
     * A feed publishes under its store's own directory and its URL secret is
     * never rotated, so the store is set once, here, and nowhere else.
     *
     * @return void
     */
    public function testOnlyCreateOffersTheStore(): void
    {
        $this->assertArrayHasKey('store_id', $this->createFeed()->getInputSchema()['properties']);
    }

    /**
     * @return void
     */
    public function testDeleteIsAWriteThatKeepsTheDestructiveDefault(): void
    {
        $tool = $this->deleteFeed();

        $this->assertTrue($tool->isWrite());
        $this->assertTrue($tool->getAnnotations()['destructiveHint']);
        // The companion guards deletion with the same resource as editing and
        // declares no separate delete grant, so this follows it rather than
        // inventing a resource no role would hold.
        $this->assertSame('Magenx_ProductFeed::feed', $tool->getAclResource());
    }

    /**
     * The published file outlives the row on purpose, and a caller who reads
     * "deleted" as "it has stopped being published" would be wrong for as long
     * as the marketplace keeps fetching it.
     *
     * @return void
     */
    public function testDeleteWarnsThatThePublishedFileKeepsBeingServed(): void
    {
        $description = $this->deleteFeed()->getDescription();

        $this->assertStringContainsString('LEFT IN PLACE', $description);
        $this->assertStringContainsString('does not stop the data being published', $description);
    }

    /**
     * @return void
     */
    public function testAFeedMidGenerationCannotBeDeleted(): void
    {
        $feed = new Feed();
        $feed->setData('feed_id', 3);
        $feed->setData('code', 'google');
        $feed->setData('cursor_position', 900);

        $locator = $this->createMock(FeedLocator::class);
        $locator->method('locate')->willReturn($feed);
        $locator->method('schemaProperties')->willReturn([]);

        $resource = $this->createMock(FeedResource::class);
        $resource->expects($this->never())->method('delete');

        $projector = $this->createMock(FeedProjector::class);
        $projector->method('toDetail')->willReturn([]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('stopped at product 900');

        (new DeleteFeed($locator, $resource, $projector))->execute(['feed_id' => 3]);
    }

    /**
     * A generation spread over several cron ticks writes one history row, when
     * it finally completes — so an empty log looks exactly like "never ran".
     * The description has to say so, because the tool cannot.
     *
     * @return void
     */
    public function testTheHistoryToolExplainsWhyAnEmptyLogIsNotProofOfNothing(): void
    {
        $description = $this->getFeedHistory()->getDescription();

        $this->assertStringContainsString('ONE row', $description);
        $this->assertStringContainsString('cursor_position', $description);
    }

    /**
     * @return void
     */
    public function testAnOversizedHistoryLimitIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('between 1 and 100');

        $this->getFeedHistory()->execute(['feed_id' => 3, 'limit' => 5000]);
    }

    /**
     * @return SearchFeeds
     */
    private function searchFeeds(): SearchFeeds
    {
        return new SearchFeeds(
            $this->createMock(FeedCollectionFactory::class),
            $this->createMock(FeedProjector::class)
        );
    }

    /**
     * @return GetFeed
     */
    private function getFeed(): GetFeed
    {
        return new GetFeed($this->locator(), $this->createMock(FeedProjector::class));
    }

    /**
     * @return GetFeedHistory
     */
    private function getFeedHistory(): GetFeedHistory
    {
        $collection = $this->createMock(HistoryCollection::class);
        $collection->method('addFeedFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $factory = $this->createMock(HistoryCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new GetFeedHistory($this->locator(), $factory);
    }

    /**
     * @return CreateFeed
     */
    private function createFeed(): CreateFeed
    {
        return new CreateFeed(
            $this->createMock(FeedFactory::class),
            $this->createMock(FeedResource::class),
            new FeedArguments($this->createMock(TemplateEngine::class)),
            $this->createMock(FeedProjector::class)
        );
    }

    /**
     * @return DeleteFeed
     */
    private function deleteFeed(): DeleteFeed
    {
        return new DeleteFeed(
            $this->locator(),
            $this->createMock(FeedResource::class),
            $this->createMock(FeedProjector::class)
        );
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
