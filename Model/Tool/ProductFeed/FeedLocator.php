<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\FeedFactory;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Turns whichever argument names a feed into the feed.
 *
 * `code` is offered beside `feed_id` because it is the handle people actually
 * use: it is unique, it is derived from the feed's name, and it is what the CLI
 * takes. An agent that has just read search_feeds has both; an agent working
 * from something a human said usually has only the code.
 */
class FeedLocator
{
    /**
     * @param FeedFactory $feedFactory
     * @param FeedResource $feedResource
     */
    public function __construct(
        private readonly FeedFactory $feedFactory,
        private readonly FeedResource $feedResource
    ) {
    }

    /**
     * @param int|null $feedId
     * @param string|null $code
     * @return Feed
     * @throws LocalizedException
     */
    public function locate(?int $feedId, ?string $code): Feed
    {
        if ($feedId === null && $code === null) {
            throw new LocalizedException(__('Pass feed_id or code to name the feed.'));
        }

        if ($feedId === null) {
            $feedId = $this->feedResource->getIdByCode((string) $code);
            if ($feedId === null) {
                throw new LocalizedException(__(
                    'No product feed exists with code "%1". Use search_feeds to see them.',
                    $code
                ));
            }
        }

        $feed = $this->feedFactory->create();
        $this->feedResource->load($feed, $feedId);

        if ($feed->getFeedId() === null) {
            throw new LocalizedException(__(
                'No product feed exists with feed_id %1. Use search_feeds to see them.',
                $feedId
            ));
        }

        return $feed;
    }

    /**
     * Schema fragment for the two arguments that name a feed.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'feed_id' => ['type' => 'integer', 'description' => 'The feed id.'],
            'code' => [
                'type' => 'string',
                'description' => 'The feed\'s unique code, if the id is not to hand.',
            ],
        ];
    }
}
