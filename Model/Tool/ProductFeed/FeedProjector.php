<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\ProductFeed\Model\Export\FeedFilesystem;
use Magenx\ProductFeed\Model\Feed;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Presents a feed definition and the state of its last run.
 *
 * Two things shape the split between summary and detail.
 *
 * A feed's `template` is a whole program in the module's template language, and
 * `field_map` and `validation_rules` are JSON documents beside it. Twenty feeds
 * would be twenty programs, so the summary leaves all three out and get_feed
 * reads one in full — the same reason search_blog_posts withholds the article
 * body.
 *
 * The rest of the row is not definition at all but run state: `status`,
 * `cursor_position`, `last_generated_at` and the error from the last attempt.
 * Those belong in both views, because "why is this feed stale" is the question
 * that brings anybody here, and the answer is usually in them.
 */
class FeedProjector
{
    /**
     * @param FeedFilesystem $feedFilesystem
     */
    public function __construct(private readonly FeedFilesystem $feedFilesystem)
    {
    }

    /**
     * @param Feed $feed
     * @return array<string, mixed>
     */
    public function toSummary(Feed $feed): array
    {
        return [
            'feed_id' => (int) $feed->getFeedId(),
            'code' => $feed->getCode(),
            'name' => (string) $feed->getData('name'),
            'store_id' => $feed->getStoreId(),
            'format' => $feed->getFormat(),
            'marketplace' => $feed->getData('marketplace'),
            'is_active' => $feed->isActive(),
            'status' => (string) $feed->getData('status'),
            'last_generated_at' => $feed->getData('last_generated_at'),
            'product_count' => $this->intOrNull($feed, 'product_count'),
            // Non-null means a run is in progress or was interrupted, which is
            // what makes a feed look stuck. Reported everywhere for that reason.
            'cursor_position' => $this->intOrNull($feed, 'cursor_position'),
            'last_error' => $feed->getData('last_error'),
        ];
    }

    /**
     * @param Feed $feed
     * @return array<string, mixed>
     */
    public function toDetail(Feed $feed): array
    {
        $detail = $this->toSummary($feed);

        $detail['description'] = $feed->getData('description');
        $detail['filename'] = (string) $feed->getData('filename');
        $detail['template'] = $feed->getData('template');
        $detail['field_map'] = $feed->getFieldMap();
        $detail['validation_rules'] = $feed->getValidationRules();
        $detail['schedule_days'] = $feed->getScheduleDays();
        $detail['schedule_times'] = $feed->getScheduleTimes();
        $detail['generation_time_ms'] = $this->intOrNull($feed, 'generation_time');
        $detail['created_at'] = $feed->getData('created_at');
        $detail['updated_at'] = $feed->getData('updated_at');

        $detail['csv_options'] = [
            'delimiter' => (string) $feed->getData('csv_delimiter'),
            'enclosure' => (string) $feed->getData('csv_enclosure'),
            'include_header' => (bool) $feed->getData('csv_include_header'),
            'bom' => (bool) $feed->getData('csv_bom'),
        ];

        // Which of the two rendering modes this feed is actually in. The module
        // picks record mode only when there is a field map AND no template, and
        // a feed carrying both silently ignores its map — so saying which one is
        // live is worth more than reporting the two fields and leaving the
        // reader to work it out.
        $detail['render_mode'] = $this->renderMode($feed);
        $detail['can_push_to_catalog_api'] = $feed->producesRecords();

        $detail['published'] = $this->published($feed);

        return $detail;
    }

    /**
     * Where the last completed run put its file, if there was one.
     *
     * The public URL is included deliberately. It is not a leak: this URL is the
     * whole point of a feed — it is what gets pasted into Google Merchant Center
     * as the fetch target — and the unguessable `url_secret` segment in it is
     * what keeps the catalogue off search engines, not a secret from whoever
     * administers the store.
     *
     * @param Feed $feed
     * @return array<string, mixed>|null
     */
    private function published(Feed $feed): ?array
    {
        $filename = (string) $feed->getData('last_filename');
        if ($filename === '') {
            return null;
        }

        $published = ['filename' => $filename];

        try {
            $published['media_path'] = $this->feedFilesystem->getRelativePath($feed, $filename);
            $published['url'] = $this->feedFilesystem->getPublicUrl($feed, $filename);
        } catch (NoSuchEntityException) {
            // The feed's store view has been deleted since the file was written.
            // The file is still there; the URL cannot be built, and saying so is
            // better than omitting the block as though nothing were published.
            $published['url'] = null;
            $published['note'] = 'The store view this feed belongs to no longer exists, '
                . 'so its public URL cannot be resolved.';
        }

        return $published;
    }

    /**
     * @param Feed $feed
     * @return string
     */
    private function renderMode(Feed $feed): string
    {
        $hasTemplate = trim((string) $feed->getData('template')) !== '';

        if ($feed->getFieldMap() !== [] && !$hasTemplate) {
            return 'field_map';
        }

        return $hasTemplate ? 'template' : 'none';
    }

    /**
     * @param Feed $feed
     * @param string $key
     * @return int|null
     */
    private function intOrNull(Feed $feed, string $key): ?int
    {
        $value = $feed->getData($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
