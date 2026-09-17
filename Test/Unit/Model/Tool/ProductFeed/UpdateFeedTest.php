<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\FeedArguments;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedLocator;
use Magenx\AiMcp\Model\Tool\ProductFeed\FeedProjector;
use Magenx\AiMcp\Model\Tool\ProductFeed\UpdateFeed;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magenx\ProductFeed\Model\Template\TemplateEngine;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Editing a feed definition.
 *
 * The case worth a test file is the one that is invisible from the arguments:
 * a feed part-way through a generation. The export writes its cursor, counts
 * and published filename straight to the row rather than saving the model,
 * precisely so it need not re-save a whole feed inside its loop — which means
 * saving a feed here that was loaded before the run started would put the
 * pre-run values back over them, and the rest of the export would then disagree
 * with the part already written.
 *
 * @see UpdateFeed::execute
 */
class UpdateFeedTest extends TestCase
{
    private FeedResource&MockObject $feedResource;
    private FeedLocator&MockObject $locator;
    private UpdateFeed $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->feedResource = $this->createMock(FeedResource::class);
        $this->locator = $this->createMock(FeedLocator::class);
        $this->locator->method('schemaProperties')->willReturn([
            'feed_id' => ['type' => 'integer'],
            'code' => ['type' => 'string'],
        ]);

        $projector = $this->createMock(FeedProjector::class);
        $projector->method('toDetail')->willReturn([]);

        $this->tool = new UpdateFeed(
            $this->locator,
            $this->feedResource,
            new FeedArguments($this->createMock(TemplateEngine::class)),
            $projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheFeedResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magenx_ProductFeed::feed', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testItAdvertisesItselfAsIdempotentAndNotDestructive(): void
    {
        $annotations = $this->tool->getAnnotations();

        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['destructiveHint']);
    }

    /**
     * The guard this file exists for.
     *
     * @return void
     */
    public function testAFeedPartWayThroughAGenerationIsRefusedAndNothingIsSaved(): void
    {
        $this->locator->method('locate')->willReturn($this->feed(['cursor_position' => 4200]));
        $this->feedResource->expects($this->never())->method('save');

        try {
            $this->tool->execute(['feed_id' => 3, 'name' => 'Renamed']);
            $this->fail('A feed mid-generation must be refused.');
        } catch (LocalizedException $e) {
            // The number is what makes it actionable: it says how far the run got.
            $this->assertStringContainsString('stopped at product 4200', $e->getMessage());
            $this->assertStringContainsString('cron_status', $e->getMessage());
        }
    }

    /**
     * A cursor of zero is still a run in progress — the column is nullable
     * exactly so that "idle" and "at the very beginning" can differ, and a
     * loose falsy check here would let an edit through at the worst moment.
     *
     * @return void
     */
    public function testACursorOfZeroStillCountsAsRunning(): void
    {
        $this->locator->method('locate')->willReturn($this->feed(['cursor_position' => 0]));
        $this->feedResource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['feed_id' => 3, 'name' => 'Renamed']);
    }

    /**
     * @return void
     */
    public function testAnIdleFeedIsUpdatedAndSaved(): void
    {
        $feed = $this->feed(['cursor_position' => null, 'name' => 'Old']);
        $this->locator->method('locate')->willReturn($feed);
        $this->feedResource->expects($this->once())->method('save')->with($feed);

        $result = $this->tool->execute(['feed_id' => 3, 'name' => 'Renamed']);

        $this->assertTrue($result['updated']);
        $this->assertSame(['name'], $result['changed_fields']);
        $this->assertSame('Renamed', $feed->getData('name'));
        // A caller that reads "updated" as "the published file changed" would
        // be wrong until the feed next generates.
        $this->assertStringContainsString('does not change until', $result['note']);
    }

    /**
     * @return void
     */
    public function testAnUpdateWithNoFieldsIsRefused(): void
    {
        $this->locator->method('locate')->willReturn($this->feed([]));
        $this->feedResource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Nothing to update');

        $this->tool->execute(['feed_id' => 3]);
    }

    /**
     * Moving a feed between stores would strand the file it already published
     * at a URL that keeps serving, because url_secret is never rotated.
     *
     * @return void
     */
    public function testTheStoreCannotBeChanged(): void
    {
        $this->assertArrayNotHasKey('store_id', $this->tool->getInputSchema()['properties']);
    }

    /**
     * @return void
     */
    public function testTheSchemaIsClosedAndNamesTheFeedTwoWays(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('feed_id', $schema['properties']);
        $this->assertArrayHasKey('code', $schema['properties']);
    }

    /**
     * @param array<string, mixed> $data
     * @return Feed
     */
    private function feed(array $data): Feed
    {
        $feed = new Feed();
        $feed->setData('feed_id', 3);
        $feed->setData('code', 'google');
        $feed->setData('template', '');
        foreach ($data as $key => $value) {
            $feed->setData($key, $value);
        }

        return $feed;
    }
}
