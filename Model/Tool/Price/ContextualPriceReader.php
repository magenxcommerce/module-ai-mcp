<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\Dimension\DimensionFactory;

/**
 * Reads what the storefront will charge one customer group for one product.
 *
 * The price index is what the storefront itself reads, so answering from it
 * gives the price a shopper sees — and an index that is behind gives the
 * stale price the shopper sees too, which is why the tool reports the
 * indexer's state beside it. The table is resolved for the website and group
 * dimensions rather than named, because a store running the price indexer in
 * dimension mode keeps one table per website and group.
 *
 * Tier prices are read from `catalog_product_entity_tier_price` keyed on
 * `entity_id`, which is Magento Open Source's layout.
 */
class ContextualPriceReader
{
    /** WebsiteDimensionProvider::DIMENSION_NAME */
    private const WEBSITE_DIMENSION = 'ws';

    /** CustomerGroupDimensionProvider::DIMENSION_NAME */
    private const GROUP_DIMENSION = 'cg';

    /**
     * @param ResourceConnection $resource
     * @param PriceTableResolver $priceTableResolver
     * @param DimensionFactory $dimensionFactory
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly PriceTableResolver $priceTableResolver,
        private readonly DimensionFactory $dimensionFactory
    ) {
    }

    /**
     * The indexed price row, or null when the index holds none — the product
     * is not in that website, is disabled, or has not been indexed yet.
     *
     * @param int $productId
     * @param int $websiteId
     * @param int $groupId
     * @return array<string, mixed>|null
     */
    public function indexedPrice(int $productId, int $websiteId, int $groupId): ?array
    {
        $table = $this->priceTableResolver->resolve('catalog_product_index_price', [
            $this->dimensionFactory->create(self::WEBSITE_DIMENSION, (string) $websiteId),
            $this->dimensionFactory->create(self::GROUP_DIMENSION, (string) $groupId),
        ]);

        $row = $this->connection()->fetchRow(
            $this->connection()->select()
                ->from(['p' => $table], ['price', 'final_price', 'min_price', 'max_price', 'tier_price'])
                ->where('p.entity_id = ?', $productId)
                ->where('p.website_id = ?', $websiteId)
                ->where('p.customer_group_id = ?', $groupId)
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The catalog price rule price for that day, or null when no rule applies.
     *
     * @param int $productId
     * @param int $websiteId
     * @param int $groupId
     * @param string $date The website's local date, "Y-m-d" — what rule_date holds.
     * @return float|null
     */
    public function rulePrice(int $productId, int $websiteId, int $groupId, string $date): ?float
    {
        $price = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from(['r' => $this->resource->getTableName('catalogrule_product_price')], ['rule_price'])
                ->where('r.product_id = ?', $productId)
                ->where('r.website_id = ?', $websiteId)
                ->where('r.customer_group_id = ?', $groupId)
                ->where('r.rule_date = ?', $date)
        );

        return $price === false || $price === null ? null : (float) $price;
    }

    /**
     * Tier prices that apply to the group and website at this quantity.
     *
     * @param int $productId
     * @param int $websiteId
     * @param int $groupId
     * @param float $qty
     * @return array<int, array<string, mixed>>
     */
    public function tierPrices(int $productId, int $websiteId, int $groupId, float $qty): array
    {
        $connection = $this->connection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    ['t' => $this->resource->getTableName('catalog_product_entity_tier_price')],
                    ['qty', 'value', 'percentage_value', 'all_groups', 'customer_group_id', 'website_id']
                )
                ->where('t.entity_id = ?', $productId)
                ->where('t.website_id IN (?)', [0, $websiteId])
                ->where($connection->quoteInto('t.all_groups = 1 OR t.customer_group_id = ?', $groupId))
                ->where('t.qty <= ?', $qty)
        );
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
