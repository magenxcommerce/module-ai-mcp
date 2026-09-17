<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\ProductFeed\FeedProjector;
use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Presenting a feed.
 *
 * Two things here are worth pinning rather than left to read.
 *
 * The summary withholds the feed body, because a feed's template is a program
 * and a page of twenty feeds would be twenty programs — the same call
 * search_blog_posts makes about article text.
 *
 * And `render_mode` is computed rather than inferred by the reader. The module
 * uses a field map only when there is no template, so a feed carrying both
 * renders from the template and ignores its columns. Reporting the two fields
 * and leaving somebody to work that out is how a wrong conclusion gets drawn
 * from correct data.
 *
 * @see FeedProjector
 */
class FeedProjectorTest extends TestCase
{
    private FeedFilesystem&MockObject $feedFilesystem;
    private FeedProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->feedFilesystem = $this->createMock(FeedFilesystem::class);
        $this->projector = new FeedProjector($this->feedFilesystem);
    }

    /**
     * @return void
     */
    public function testTheSummaryWithholdsTheFeedBody(): void
    {
        $summary = $this->projector->toSummary($this->feed(['template' => '<rss>...</rss>']));

        foreach (['template', 'field_map', 'validation_rules'] as $key) {
            $this->assertArrayNotHasKey($key, $summary, $key);
        }
    }

    /**
     * Run state belongs in both views: "why is this feed stale" is the question
     * that brings anybody here, and the answer is usually in it.
     *
     * @return void
     */
    public function testTheSummaryCarriesTheRunState(): void
    {
        $summary = $this->projector->toSummary($this->feed([
            'status' => Feed::STATUS_ERROR,
            'last_error' => 'Attribute "colour" does not exist.',
            'cursor_position' => 800,
        ]));

        $this->assertSame(Feed::STATUS_ERROR, $summary['status']);
        $this->assertSame('Attribute "colour" does not exist.', $summary['last_error']);
        $this->assertSame(800, $summary['cursor_position']);
    }

    /**
     * @return void
     */
    public function testAFeedWithBothATemplateAndAFieldMapIsReportedAsTemplateDriven(): void
    {
        $detail = $this->projector->toDetail($this->feed([
            'template' => '<rss/>',
            'field_map' => json_encode([['column' => 'sku', 'value' => '{{ product.sku }}']]),
        ]));

        // The map is present in the row and ignored by the module.
        $this->assertSame('template', $detail['render_mode']);
    }

    /**
     * @return void
     */
    public function testAFieldMapWithNoTemplateIsReportedAsRecordMode(): void
    {
        $detail = $this->projector->toDetail($this->feed([
            'template' => '',
            'field_map' => json_encode([['column' => 'sku', 'value' => '{{ product.sku }}']]),
        ]));

        $this->assertSame('field_map', $detail['render_mode']);
    }

    /**
     * @return void
     */
    public function testAFeedWithNeitherIsReportedAsNone(): void
    {
        $this->assertSame('none', $this->projector->toDetail($this->feed([]))['render_mode']);
    }

    /**
     * Only csv, tsv and jsonl can feed a push catalog API; choosing xml is what
     * silently makes the Meta destination unusable later.
     *
     * @return void
     */
    public function testWhetherTheFormatCanPushToACatalogApiIsReported(): void
    {
        $this->assertTrue($this->projector->toDetail($this->feed(['format' => 'csv']))['can_push_to_catalog_api']);
        $this->assertFalse($this->projector->toDetail($this->feed(['format' => 'xml']))['can_push_to_catalog_api']);
    }

    /**
     * @return void
     */
    public function testAFeedThatHasNeverPublishedReportsNoFile(): void
    {
        $this->assertNull($this->projector->toDetail($this->feed([]))['published']);
    }

    /**
     * The public URL is what gets pasted into a marketplace as the fetch
     * target, so reporting it is the point rather than a leak.
     *
     * @return void
     */
    public function testAPublishedFeedReportsItsPathAndUrl(): void
    {
        $this->feedFilesystem->method('getRelativePath')->willReturn('magenx-feed/default/abc/f.csv');
        $this->feedFilesystem->method('getPublicUrl')
            ->willReturn('https://example.test/media/magenx-feed/default/abc/f.csv');

        $published = $this->projector->toDetail($this->feed(['last_filename' => 'f.csv']))['published'];

        $this->assertSame('f.csv', $published['filename']);
        $this->assertSame('https://example.test/media/magenx-feed/default/abc/f.csv', $published['url']);
    }

    /**
     * A file published before its store view was deleted is still on disk. The
     * URL cannot be rebuilt, and saying so beats dropping the block as though
     * nothing had ever been published.
     *
     * @return void
     */
    public function testADeletedStoreViewIsReportedRatherThanHidingTheFile(): void
    {
        $this->feedFilesystem->method('getRelativePath')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $published = $this->projector->toDetail($this->feed(['last_filename' => 'f.csv']))['published'];

        $this->assertSame('f.csv', $published['filename']);
        $this->assertNull($published['url']);
        $this->assertStringContainsString('no longer exists', $published['note']);
    }

    /**
     * @return void
     */
    public function testScheduleAndCsvOptionsAreReportedAsStructure(): void
    {
        $detail = $this->projector->toDetail($this->feed([
            'schedule_days' => '1,5',
            'schedule_times' => '06:30,18:00',
            'csv_delimiter' => 'pipe',
            'csv_include_header' => 1,
        ]));

        $this->assertSame([1, 5], $detail['schedule_days']);
        $this->assertSame(['06:30', '18:00'], $detail['schedule_times']);
        $this->assertSame('pipe', $detail['csv_options']['delimiter']);
        $this->assertTrue($detail['csv_options']['include_header']);
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
        $feed->setData('name', 'Google Shopping');
        $feed->setData('store_id', 1);
        $feed->setData('format', 'csv');
        foreach ($data as $key => $value) {
            $feed->setData($key, $value);
        }

        return $feed;
    }
}
