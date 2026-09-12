<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Inventory;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * List the products at or below a quantity.
 */
class SearchLowStock extends AbstractTool
{
    /**
     * @param StockRegistryInterface $stockRegistry
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_low_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List products whose quantity is at or below a threshold you give. Reports sku and '
            . 'quantity only; call get_stock for one sku\'s full record. This is a '
            . 'plain quantity comparison against the number you pass, not Magento\'s own '
            . '"low stock" report, which compares each product against its notify_stock_qty.';
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
                    'qty' => [
                        'type' => 'number',
                        'description' => 'Report products whose quantity is at or below this.',
                    ],
                    'website_id' => [
                        'type' => 'integer',
                        'description' => 'Website whose stock to read. Omit for the default stock, '
                            . 'which is the only one on a single-website store.',
                    ],
                ],
                $this->pagingSchema()
            ),
            'required' => ['qty'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_CatalogInventory::cataloginventory';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $qty = $arguments['qty'] ?? null;
        if (!is_int($qty) && !is_float($qty) && !(is_string($qty) && is_numeric($qty))) {
            throw new LocalizedException(__('The "qty" argument must be a number.'));
        }

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        // Scope 0 is the default stock, which is what a single-website store
        // has; the argument is not optional on Magento's side.
        $websiteId = $this->optionalInt($arguments, 'website_id') ?? 0;

        $items = array_values(
            $this->stockRegistry->getLowStockItems($websiteId, (float) $qty, $currentPage, $pageSize)->getItems()
        );

        return [
            'page' => $currentPage,
            'page_size' => $pageSize,
            'returned_count' => count($items),
            'items' => $this->withSkus($items),
        ];
    }

    /**
     * Attach each item's sku.
     *
     * Magento's low-stock query returns stock rows, which identify a product by
     * id and not by sku, and a sku is what every other tool here takes. The
     * ids are resolved in one query rather than one per row.
     *
     * @param StockItemInterface[] $items
     * @return array<int, array<string, mixed>>
     */
    private function withSkus(array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            if ($item->getProductId() !== null) {
                $productIds[] = (int) $item->getProductId();
            }
        }

        $skusById = [];
        if ($productIds !== []) {
            $this->searchCriteriaBuilder->addFilter('entity_id', $productIds, 'in');
            $products = $this->productRepository->getList($this->searchCriteriaBuilder->create())->getItems();
            foreach ($products as $product) {
                $skusById[(int) $product->getId()] = $product->getSku();
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $productId = $item->getProductId() === null ? null : (int) $item->getProductId();
            $rows[] = [
                'sku' => $productId === null ? null : ($skusById[$productId] ?? null),
                'product_id' => $productId,
                'qty' => $item->getQty() === null ? null : (float) $item->getQty(),
            ];
        }

        return $rows;
    }
}
