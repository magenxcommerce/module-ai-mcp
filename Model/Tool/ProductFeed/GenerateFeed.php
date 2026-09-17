<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\Config as FeedConfig;
use Magenx\ProductFeed\Model\Export\RunResult;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedManager;
use Magenx\ProductFeed\Model\ResourceModel\Delivery\CollectionFactory as DeliveryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Run one slice of a feed's export, and deliver it if that finishes the job.
 *
 * Two things about this tool are easy to get wrong, and both are the sort of
 * wrong that looks like success.
 *
 * **It publishes outward.** `FeedManager::process()` is not only a generate: a
 * run that completes is immediately delivered to every active destination,
 * which may be an FTP drop, Google Merchant Center or Meta's catalog API. That
 * is not a side effect to route around — push destinations are prepared before
 * the run and fed records as products are exported, so generating without
 * delivering would mean going around `FeedManager` entirely and a feed Google
 * is meant to receive would silently never arrive. So this calls the same entry
 * point the admin button, the CLI and cron all call, and says up front what a
 * completed run will reach.
 *
 * **One call is one slice, not one feed.** The export runs until the store's
 * configured execution budget is spent, writes its cursor, and stops. Cron
 * continues from there. On any real catalogue the first call therefore returns
 * a *partial* result — and `RunResult` is explicit that a partial result is a
 * success, not a failure: `completed` false with `failed` false means the tick
 * did its work and there is more to do. Reporting that as an error would send
 * an agent looking for a fault that is not there; reporting it as done would be
 * worse.
 *
 * The enabled check is made here rather than left to the module. `process()`
 * answers a disabled store with the same `skipped` result it uses for "a run is
 * already in progress", distinguishable only by an English message string.
 * Checking first means the two causes never have to be told apart by matching
 * on prose.
 */
class GenerateFeed extends AbstractTool
{
    /**
     * @param FeedLocator $locator
     * @param FeedManager $feedManager
     * @param FeedConfig $feedConfig
     * @param DeliveryCollectionFactory $deliveryCollectionFactory
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly FeedManager $feedManager,
        private readonly FeedConfig $feedConfig,
        private readonly DeliveryCollectionFactory $deliveryCollectionFactory,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'generate_feed';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Run a product feed now. This exports ONE time-budgeted slice — on a large '
            . 'catalogue it will usually report that it generated part of the feed and that cron '
            . 'will continue, which is a success and not an error. When a run finishes, the feed '
            . 'is IMMEDIATELY DELIVERED to every active destination, which may publish the '
            . 'catalogue to an FTP server, Google Merchant Center or Meta — list_feed_deliveries '
            . 'shows where before you call this. Behind its own ACL resource, separate from the '
            . 'one that edits feeds.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        // The companion's own second grant. Defining a feed and publishing the
        // catalogue outward are different permissions, and it already says so.
        return 'Magenx_ProductFeed::generate';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function isDestructive(): bool
    {
        // It replaces the published file and pushes to external destinations.
        // Nothing in the store is lost, but what a marketplace holds is
        // overwritten, and that cannot be taken back from here.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $feed = $this->locator->locate(
            $this->optionalInt($arguments, 'feed_id'),
            $this->optionalString($arguments, 'code')
        );

        $this->assertFeedsAreEnabled($feed);

        $destinations = $this->activeDestinations($feed);

        $result = $this->feedManager->process($feed);

        // The export writes its cursor, published filename and counts straight
        // to the row rather than saving the model, so the instance passed into
        // process() still holds the values it had before the run. Reporting
        // from it would give a cursor that had not moved and a published file
        // that was last week's. Re-read it.
        $fresh = $this->locator->locate((int) $feed->getFeedId(), null);

        return [
            'tool' => $this->getName(),
            'feed_id' => (int) $fresh->getFeedId(),
            'code' => $fresh->getCode(),
            'active_destinations' => $destinations,
        ] + $this->describe($result, $fresh, $destinations);
    }

    /**
     * Turn one RunResult into something that cannot be misread.
     *
     * @param RunResult $result
     * @param Feed $feed The feed re-read after the run, not the one passed into it.
     * @param string[] $destinations
     * @return array<string, mixed>
     */
    private function describe(RunResult $result, Feed $feed, array $destinations): array
    {
        if ($result->failed) {
            return [
                'outcome' => 'failed',
                'message' => $result->message,
                'duration_ms' => $result->durationMs,
                'delivered' => false,
            ];
        }

        if ($result->skipped) {
            // The disabled case is refused before process() is reached, so the
            // only skip that can arrive here is the export lock.
            return [
                'outcome' => 'skipped',
                'message' => $result->message,
                'delivered' => false,
                'note' => 'A generation of this feed is already running — started by cron, by the '
                    . 'admin, or by an earlier call. Nothing was changed. get_feed_history reports '
                    . 'how it ends.',
            ];
        }

        if (!$result->completed) {
            return [
                'outcome' => 'partial',
                // Said in words as well as in the flag, because "completed:
                // false" beside no error is the shape most likely to be read as
                // a failure.
                'message' => 'Part of the feed was generated; this is normal and not an error. '
                    . 'The export used its time budget and stopped, and cron will continue from '
                    . 'where it left off.',
                'products_written_so_far' => $result->productCount,
                'cursor_position' => $this->cursor($feed),
                'duration_ms' => $result->durationMs,
                'delivered' => false,
                'note' => 'Nothing is published or delivered until the run finishes. Cron must be '
                    . 'running for that to happen — cron_status says whether it is.',
            ];
        }

        return [
            'outcome' => 'completed',
            'message' => $result->message,
            'product_count' => $result->productCount,
            'duration_ms' => $result->durationMs,
            'published' => $this->projector->toDetail($feed)['published'] ?? null,
            'delivered' => $destinations !== [],
            'delivered_to' => $destinations,
            'note' => $destinations === []
                ? 'The file is published at its URL. No active destinations are configured, so '
                    . 'nothing was pushed anywhere.'
                : 'The feed was delivered to the destinations listed. get_feed_history reports '
                    . 'the outcome of each.',
        ];
    }

    /**
     * @param Feed $feed
     * @return void
     * @throws LocalizedException
     */
    private function assertFeedsAreEnabled(Feed $feed): void
    {
        if ($this->feedConfig->isEnabled($feed->getStoreId())) {
            return;
        }

        throw new LocalizedException(__(
            'Product feeds are switched off for the store view this feed belongs to, so running '
            . 'it would do nothing at all and report no error. Switch on '
            . '"magenx_product_feed/general/enabled" for store %1 — set_config can do it — and '
            . 'call this again.',
            $feed->getStoreId()
        ));
    }

    /**
     * Destinations a completed run will reach, read before the run.
     *
     * Named in the result whatever the outcome, so the confirm preview says
     * where this call would publish to rather than only that it would generate.
     *
     * @param Feed $feed
     * @return string[]
     */
    private function activeDestinations(Feed $feed): array
    {
        $collection = $this->deliveryCollectionFactory->create();
        $collection->addFeedFilter((int) $feed->getFeedId());
        $collection->addActiveFilter();

        $types = [];
        foreach ($collection as $delivery) {
            $types[] = $delivery->getType();
        }

        return $types;
    }

    /**
     * @param Feed $feed
     * @return int|null
     */
    private function cursor(Feed $feed): ?int
    {
        $cursor = $feed->getData('cursor_position');

        return $cursor === null || $cursor === '' ? null : (int) $cursor;
    }
}
