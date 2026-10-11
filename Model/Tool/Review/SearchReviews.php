<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Review;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;

/**
 * Product reviews, newest first — the moderation queue by default.
 *
 * Personal data: a review carries the reviewer's nickname and, when they were
 * signed in, their customer id.
 */
class SearchReviews extends AbstractTool implements PersonalDataToolInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductResource $productResource
     * @param StoreResolver $storeResolver
     * @param ReviewProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductResource $productResource,
        private readonly StoreResolver $storeResolver,
        private readonly ReviewProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_reviews';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Product reviews, newest first, with their rating votes. Filter by status (pending is '
            . 'the moderation queue), product sku, customer, store or date. Review text is cut at '
            . '2000 characters.';
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
                    'status' => [
                        'type' => 'string',
                        'enum' => array_keys(ReviewProjector::STATUSES),
                        'description' => 'Only reviews in this status.',
                    ],
                    'sku' => ['type' => 'string', 'description' => 'Only reviews of this product.'],
                    'customer_id' => ['type' => 'integer', 'description' => 'Only reviews by this customer.'],
                    'store_code' => [
                        'type' => 'string',
                        'description' => 'Only reviews visible on this store view.',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Written on or after this date, "YYYY-MM-DD", UTC.',
                    ],
                    'created_to' => [
                        'type' => 'string',
                        'description' => 'Written on or before this date, "YYYY-MM-DD", UTC, inclusive.',
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
        return 'Magento_Review::reviews_all';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $status = $this->optionalString($arguments, 'status');
        if ($status !== null) {
            if (!isset(ReviewProjector::STATUSES[$status])) {
                throw new LocalizedException(
                    __(
                        'The "status" argument must be one of: %1.',
                        implode(', ', array_keys(ReviewProjector::STATUSES))
                    )
                );
            }
            $collection->addStatusFilter(ReviewProjector::STATUSES[$status]);
        }

        $sku = $this->optionalString($arguments, 'sku');
        if ($sku !== null) {
            $productId = $this->productResource->getIdBySku($sku);
            if (!$productId) {
                throw new LocalizedException(__('There is no product with sku "%1".', $sku));
            }
            $collection->addEntityFilter('product', (int) $productId);
        }

        $customerId = $this->optionalInt($arguments, 'customer_id');
        if ($customerId !== null) {
            $collection->addCustomerFilter($customerId);
        }

        $storeCode = $this->optionalString($arguments, 'store_code');
        if ($storeCode !== null) {
            $collection->addStoreFilter($this->storeResolver->resolve($storeCode));
        }

        foreach (['created_from' => 'gteq', 'created_to' => 'lteq'] as $key => $operator) {
            $date = $this->optionalString($arguments, $key);
            if ($date === null) {
                continue;
            }
            if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                throw new LocalizedException(__('The "%1" argument must be a date, "YYYY-MM-DD".', $key));
            }
            $collection->addFieldToFilter(
                'main_table.created_at',
                [$operator => $date . ($operator === 'lteq' ? ' 23:59:59' : ' 00:00:00')]
            );
        }

        $collection->setDateOrder();
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);
        $collection->addRateVotes();

        $items = [];
        foreach ($collection as $review) {
            $votes = $review->getRatingVotes();
            $items[] = $this->projector->toArray($review, $votes === null ? [] : $this->projector->votes($votes));
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
