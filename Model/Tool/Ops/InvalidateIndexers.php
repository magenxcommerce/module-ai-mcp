<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Mark indexers invalid so the next cron run rebuilds them.
 *
 * It deliberately does NOT reindex synchronously. A full catalog reindex takes
 * minutes to hours; running it inside an HTTP request would hold a PHP worker
 * for the duration and time out long before finishing, leaving the caller
 * unable to tell success from failure. Invalidating is the honest operation
 * this transport can complete.
 */
class InvalidateIndexers extends AbstractTool
{
    /**
     * @param IndexerConfig $indexerConfig
     * @param IndexerRegistry $indexerRegistry
     */
    public function __construct(
        private readonly IndexerConfig $indexerConfig,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'invalidate_indexers';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Mark indexers invalid so Magento rebuilds them on the next indexer cron run. '
            . 'Use after a bulk catalog change. This does not reindex immediately — a synchronous '
            . 'reindex cannot complete inside an HTTP request. For an immediate rebuild run '
            . '"bin/magento indexer:reindex" on the server.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'indexers' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Indexer ids such as ["catalog_category_product", "catalogsearch_fulltext"]. '
                        . 'Omit to invalidate every indexer. Use indexer_status to see the ids.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Indexer::index';
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
        $available = array_keys($this->indexerConfig->getIndexers());
        $requested = array_values(array_filter($this->optionalArray($arguments, 'indexers'), 'is_string'));
        if ($requested === []) {
            $requested = $available;
        }

        $unknown = array_values(array_diff($requested, $available));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Unknown indexers: %1. Use indexer_status to list the valid ids.',
                implode(', ', $unknown)
            ));
        }

        foreach ($requested as $indexerId) {
            $this->indexerRegistry->get($indexerId)->invalidate();
        }

        return [
            'invalidated' => $requested,
            'note' => 'These will rebuild on the next indexer cron run.',
        ];
    }
}
