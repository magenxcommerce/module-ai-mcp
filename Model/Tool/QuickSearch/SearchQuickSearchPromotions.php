<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\ResourceModel\Promotion\CollectionFactory;
use Magenx\QuickSearchGraphQl\Model\Source\Type;

/**
 * Find quick search promotions.
 *
 * Ordered the way the storefront orders them — `sort_order`, then id — so a
 * page filtered to one store view reads as that store's pool, top first.
 * Whether each one actually shows is per store view and costs lookups, so it
 * is left to get_quick_search_promotion.
 */
class SearchQuickSearchPromotions extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param PromotionProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PromotionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_quick_search_promotions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the sponsored items of the storefront\'s quick-search dropdown — products, '
            . 'categories and brands shown when the search box opens, or below the results when '
            . 'a search matches their keywords. Ordered as the storefront orders them. Each '
            . 'reports its schedule (disabled, scheduled, live, expired); whether it actually '
            . 'shows in a store view is reported by get_quick_search_promotion.';
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
                        'description' => 'Substring matched against title, target and keywords.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => [Type::PRODUCT, Type::CATEGORY, Type::BRAND],
                    ],
                    'target' => [
                        'type' => 'string',
                        'description' => 'Exact target: a SKU, category id or brand option id. '
                            . 'Answers "is this already promoted".',
                    ],
                    'store_id' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'description' => 'Promotions that apply to this store view: its own plus '
                            . 'those for every store view. 0 returns only the ones for every '
                            . 'store view.',
                    ],
                    'is_active' => ['type' => 'boolean'],
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
        return 'Magenx_QuickSearchGraphQl::promotion';
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
            $collection->addFieldToFilter(['title', 'target', 'keywords'], [$like, $like, $like]);
        }

        foreach (['type', 'target'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $collection->addFieldToFilter($field, $value);
            }
        }

        $storeId = $this->optionalInt($arguments, 'store_id');
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', ['in' => array_unique([0, $storeId])]);
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $collection->setOrder('sort_order', 'ASC');
        $collection->setOrder('promotion_id', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $promotion) {
            /** @var Promotion $promotion */
            $items[] = $this->projector->toSummary($promotion);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
