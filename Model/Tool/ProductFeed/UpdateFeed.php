<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change a feed's definition.
 *
 * The feed is loaded and mutated rather than rebuilt, which keeps its condition
 * tree — the subset of the catalogue it exports — intact through an edit that
 * does not mention it. Rebuilding from the arguments would save every feed with
 * no conditions, quietly turning a curated feed into the entire catalogue.
 *
 * Two things are deliberately not writable here.
 *
 * **`store_id`.** The published file lives at
 * `magenx-feed/<store_code>/<url_secret>/<filename>`, and `url_secret` is
 * assigned once and never rotated. Moving a feed to another store would leave
 * the old file being served at the old URL indefinitely, while the row pointed
 * somewhere else — a marketplace would go on fetching stale data with nothing
 * anywhere reporting a problem.
 *
 * **Run state** — `status`, `cursor_position`, `last_filename` and the rest.
 * The export writes those straight to the row as it goes, rather than saving
 * the model, so that it need not re-save a whole feed inside its loop. Saving a
 * feed here that was loaded before a run started would put the pre-run values
 * back over them. That is also why this refuses outright while a run is in
 * progress: the race is real, and a half-written export whose definition
 * changed underneath it produces a file whose second half disagrees with its
 * first.
 */
class UpdateFeed extends AbstractTool
{
    /**
     * @param FeedLocator $locator
     * @param FeedResource $feedResource
     * @param FeedArguments $arguments
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly FeedResource $feedResource,
        private readonly FeedArguments $arguments,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_feed';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a product feed: rename it, switch its schedule on or off, edit its '
            . 'template or field map, adjust its CSV options. Only the fields you pass are '
            . 'changed, and the feed keeps the catalogue conditions set in the admin. The store '
            . 'view cannot be changed — a feed publishes under its store\'s own path, so moving '
            . 'it would strand the file it already published. Refused while a generation is in '
            . 'progress. Changes reach the published file only when the feed next generates.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                $this->arguments->schemaProperties()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_ProductFeed::feed';
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
        // Changes the fields passed on one feed; removes nothing and publishes
        // nothing.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
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

        $this->assertNotRunning($feed);

        $changed = $this->arguments->applyTo($feed, $arguments, false);
        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides feed_id or code.')
            );
        }

        $this->feedResource->save($feed);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'note' => 'The published file does not change until the feed next generates.',
        ] + $this->projector->toDetail($feed);
    }

    /**
     * @param Feed $feed
     * @return void
     * @throws LocalizedException
     */
    private function assertNotRunning(Feed $feed): void
    {
        // A non-null cursor is exactly how the module marks a run as unfinished:
        // Schedule::isDue() treats any such feed as due so cron keeps going
        // until the export completes or fails.
        if ($feed->getData('cursor_position') === null) {
            return;
        }

        throw new LocalizedException(__(
            'Feed "%1" is part-way through a generation, stopped at product %2, so its definition '
            . 'cannot be changed yet — the rest of the export would disagree with the part '
            . 'already written. It clears when cron finishes the run or the run fails; '
            . 'get_feed_history reports the outcome, and cron_status says whether cron is running '
            . 'at all.',
            $feed->getCode(),
            (int) $feed->getData('cursor_position')
        ));
    }
}
