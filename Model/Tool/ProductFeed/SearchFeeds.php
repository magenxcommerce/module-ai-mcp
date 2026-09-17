<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\ProductFeed;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductFeed\Model\Feed;
use Magenx\ProductFeed\Model\ResourceModel\Feed\CollectionFactory;

/**
 * Find product feeds.
 *
 * The feed body is never returned here. A feed's template is a program in the
 * module's template language, and twenty feeds would be twenty programs;
 * get_feed reads one in full.
 */
class SearchFeeds extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param FeedProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly FeedProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_feeds';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the product feeds — the catalogue exports published for Google Merchant '
            . 'Center, Meta and similar. Reports each feed\'s schedule state, when it last '
            . 'generated, how many products it wrote and the error from its last attempt, which '
            . 'is usually the answer to "why is this feed stale". The feed template is not '
            . 'returned here; get_feed reads one in full.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Free text matched as a substring against name and code.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'True for feeds cron regenerates on a schedule.',
                    ],
                    'store_id' => [
                        'type' => 'integer',
                        'description' => 'Only feeds belonging to this store view.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => [
                            Feed::STATUS_NOT_GENERATED,
                            Feed::STATUS_PROCESSING,
                            Feed::STATUS_READY,
                            Feed::STATUS_WARNING,
                            Feed::STATUS_ERROR,
                            Feed::STATUS_DISABLED,
                        ],
                        'description' => 'State of the last run.',
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => [
                            Feed::FORMAT_XML,
                            Feed::FORMAT_CSV,
                            Feed::FORMAT_TSV,
                            Feed::FORMAT_JSONL,
                        ],
                    ],
                    'marketplace' => [
                        'type' => 'string',
                        'description' => 'Exact marketplace label, e.g. "google" or "meta".',
                    ],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            $like = ['like' => '%' . $query . '%'];
            $collection->addFieldToFilter(['name', 'code'], [$like, $like]);
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $storeId = $this->optionalInt($arguments, 'store_id');
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }

        foreach (['status', 'format', 'marketplace'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $collection->addFieldToFilter($field, $value);
            }
        }

        $collection->setOrder('name', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $feed) {
            /** @var Feed $feed */
            $items[] = $this->projector->toSummary($feed);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
