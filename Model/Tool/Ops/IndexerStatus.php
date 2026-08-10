<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Report every indexer's state and mode.
 */
class IndexerStatus extends AbstractTool
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
        return 'indexer_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List every Magento indexer with its id, title, state (valid, invalid, working), '
            . 'mode (realtime or by schedule) and when it last ran.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
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
    public function execute(array $arguments): array
    {
        $indexers = [];
        foreach (array_keys($this->indexerConfig->getIndexers()) as $indexerId) {
            $indexer = $this->indexerRegistry->get($indexerId);
            $indexers[] = [
                'id' => $indexer->getId(),
                'title' => (string) $indexer->getTitle(),
                'status' => $indexer->getStatus(),
                'is_valid' => $indexer->isValid(),
                'mode' => $indexer->isScheduled() ? 'by_schedule' : 'realtime',
                'updated' => $indexer->getLatestUpdated(),
            ];
        }

        return ['indexers' => $indexers];
    }
}
