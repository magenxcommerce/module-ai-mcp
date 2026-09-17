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
 * Delete a feed definition — and leave its published file alone.
 *
 * That last part is the module's decision, not an oversight, and the tool
 * follows it rather than improving on it. Deleting the row takes the delivery
 * and history rows with it, but the file stays where it was published, because
 * a marketplace may still be fetching that URL and removing it would turn a
 * deletion here into a broken feed on somebody else's platform.
 *
 * It is worth noticing that this is the opposite conclusion from
 * delete_media_gallery_asset, which refuses a delete precisely *because*
 * references would break. The principle is the same — do not silently break
 * something that points at this — and the difference is who holds the
 * reference. A CMS page is inside this store and can be fixed; a Google
 * Merchant Center fetch schedule is not, and cannot. So there the answer is to
 * refuse, and here it is to leave the file and say so.
 *
 * The result therefore names the file that is still being served, because an
 * agent that reads "deleted" as "the feed has stopped being published" would be
 * wrong, and would be wrong for as long as the marketplace keeps fetching.
 */
class DeleteFeed extends AbstractTool
{
    /**
     * @param FeedLocator $locator
     * @param FeedResource $feedResource
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly FeedResource $feedResource,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_feed';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a product feed definition, along with its delivery settings and run '
            . 'history. This cannot be undone. The file it last published is deliberately LEFT IN '
            . 'PLACE and keeps being served at its URL — a marketplace fetching it would '
            . 'otherwise see a broken feed rather than an unchanged one — so deleting the feed '
            . 'does not stop the data being published. Remove the fetch schedule at the '
            . 'marketplace as well. To stop a feed regenerating but keep it, set is_active false '
            . 'with update_feed instead.';
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
        // The companion guards deletion with the same resource as editing; it
        // declares no separate delete grant, so this follows the module rather
        // than inventing a resource that no role would hold.
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
    public function execute(array $arguments): array
    {
        $feed = $this->locator->locate(
            $this->optionalInt($arguments, 'feed_id'),
            $this->optionalString($arguments, 'code')
        );

        $this->assertNotRunning($feed);

        // Read the projection while the feed still exists; afterwards there is
        // nothing left to describe, and the published file is the part of it
        // that outlives the row.
        $detail = $this->projector->toDetail($feed);

        $this->feedResource->delete($feed);

        $result = [
            'deleted' => true,
            'tool' => $this->getName(),
            'feed_id' => $detail['feed_id'],
            'code' => $detail['code'],
            'name' => $detail['name'],
            'deleted_with_it' => 'Delivery settings and run history.',
        ];

        $published = $detail['published'] ?? null;
        $result['published_file_left_in_place'] = $published;
        $result['note'] = $published === null
            ? 'This feed had never published a file, so nothing is left being served.'
            : 'The file this feed published is still being served at its URL. Deleting the feed '
                . 'does not stop that — remove the fetch schedule at the marketplace too.';

        return $result;
    }

    /**
     * @param Feed $feed
     * @return void
     * @throws LocalizedException
     */
    private function assertNotRunning(Feed $feed): void
    {
        if ($feed->getData('cursor_position') === null) {
            return;
        }

        throw new LocalizedException(__(
            'Feed "%1" is part-way through a generation, stopped at product %2. Deleting it now '
            . 'would leave the run writing to a work file for a feed that no longer exists. It '
            . 'clears when cron finishes the run or the run fails; cron_status says whether cron '
            . 'is running at all.',
            $feed->getCode(),
            (int) $feed->getData('cursor_position')
        ));
    }
}
