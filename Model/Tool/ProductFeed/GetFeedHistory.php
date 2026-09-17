<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\FeedHistory;
use Magenx\ProductFeed\Model\History;
use Magenx\ProductFeed\Model\ResourceModel\History\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Read a feed's run log.
 *
 * The one thing worth knowing before reading this: **a run that used up its
 * time budget writes no row at all.** The module records a generate row only
 * when a run completes, so a feed being exported in slices across several cron
 * ticks shows one row for the whole thing, dated when it finished — not one per
 * tick. An empty log therefore does not mean nothing has happened; it can mean
 * the first run has not finished yet, which `cursor_position` on the feed
 * itself is what actually answers.
 *
 * Both facts are in the tool's description rather than left for the reader,
 * because an agent that treats an empty log as "never ran" will draw exactly
 * the wrong conclusion about a large catalogue.
 */
class GetFeedHistory extends AbstractTool
{
    /** History rows are pruned on a retention window, but a busy feed still has many. */
    private const DEFAULT_LIMIT = 20;

    /** Enough to see a pattern without returning a log file. */
    private const MAX_LIMIT = 100;

    /**
     * @param FeedLocator $locator
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly FeedLocator $locator,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_feed_history';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a product feed\'s run log, newest first: what was generated, delivered or '
            . 'validated, whether it succeeded, how long it took and how many products it wrote. '
            . 'Note that a generation spread across several cron runs writes ONE row, when it '
            . 'finally completes — so an empty log can mean the first run has not finished '
            . 'rather than that nothing ran. get_feed reports cursor_position, which is what '
            . 'distinguishes the two.';
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
                [
                    'type' => [
                        'type' => 'string',
                        'enum' => [
                            FeedHistory::TYPE_GENERATE,
                            FeedHistory::TYPE_DELIVER,
                            FeedHistory::TYPE_VALIDATE,
                        ],
                        'description' => 'Only entries of this kind.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['success', 'warning', 'error', 'skipped'],
                        'description' => 'Only entries with this outcome.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many entries to return, at most '
                            . self::MAX_LIMIT . '. Defaults to ' . self::DEFAULT_LIMIT . '.',
                    ],
                ]
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
    public function execute(array $arguments): array
    {
        $feed = $this->locator->locate(
            $this->optionalInt($arguments, 'feed_id'),
            $this->optionalString($arguments, 'code')
        );

        $collection = $this->collectionFactory->create();
        // addFeedFilter also sets the newest-first order the module intends.
        $collection->addFeedFilter((int) $feed->getFeedId());

        foreach (['type', 'status'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $collection->addFieldToFilter($field, $value);
            }
        }

        $collection->setPageSize($this->limit($arguments));
        $collection->setCurPage(1);

        $items = [];
        foreach ($collection as $entry) {
            /** @var History $entry */
            $items[] = [
                'history_id' => (int) $entry->getId(),
                'type' => (string) $entry->getData('type'),
                'status' => (string) $entry->getData('status'),
                'message' => $entry->getData('message'),
                'product_count' => $this->intOrNull($entry, 'product_count'),
                'duration_ms' => $this->intOrNull($entry, 'duration_ms'),
                'details' => $entry->getDetails(),
                'created_at' => $entry->getData('created_at'),
            ];
        }

        return [
            'feed_id' => (int) $feed->getFeedId(),
            'code' => $feed->getCode(),
            'total_count' => (int) $collection->getSize(),
            'items' => $items,
            // The feed's own cursor answers the question the log cannot.
            'run_in_progress' => $feed->getData('cursor_position') !== null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return int
     * @throws LocalizedException
     */
    private function limit(array $arguments): int
    {
        $limit = $this->optionalInt($arguments, 'limit');
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new LocalizedException(
                __('The "limit" argument must be between 1 and %1.', self::MAX_LIMIT)
            );
        }

        return $limit;
    }

    /**
     * @param History $entry
     * @param string $key
     * @return int|null
     */
    private function intOrNull(History $entry, string $key): ?int
    {
        $value = $entry->getData($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
