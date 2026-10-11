<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;

/**
 * Sales per category, with the one decision such a report cannot avoid.
 *
 * A product sits in several categories, so a sale either counts in full in
 * each of them — and the categories add up to more than was sold — or is
 * split between them, and they add up to exactly what was sold. Both are
 * useful and neither is wrong, but a figure that does not say which it is
 * will be added up by somebody. So the caller chooses, and the result says.
 *
 * The same rules as {@see SalesAggregator}: base currency and grouped by it,
 * top-level order lines only, canceled orders out. Category membership is
 * today's, not the one in force when the order was placed — Magento keeps no
 * history of it.
 *
 * Root categories (level 0 and 1) are left out of both the membership and the
 * split, because every product sits under one and it would take a share of
 * every sale without telling anyone anything.
 */
class CategoryAggregator
{
    public const ALLOCATION_SPLIT = 'split';
    public const ALLOCATION_FULL = 'full';

    /** Shallowest category level that is a real category rather than a root. */
    private const MIN_LEVEL = 2;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param Period $period
     * @param int|null $storeId
     * @param string $allocation One of the ALLOCATION_* constants.
     * @param int|null $parentCategoryId Restrict to this category's descendants.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     * @throws LocalizedException When the parent category does not exist.
     */
    public function categorySales(
        Period $period,
        ?int $storeId,
        string $allocation,
        ?int $parentCategoryId,
        int $limit
    ): array {
        $subtree = $parentCategoryId === null ? null : $this->subtreePrefix($parentCategoryId);
        $weight = $allocation === self::ALLOCATION_SPLIT ? '(1 / pc.category_count)' : '1';

        $select = $this->lines($period, $storeId)
            ->joinInner(['ccp' => $this->table('catalog_category_product')], 'ccp.product_id = i.product_id', [])
            ->joinInner(['ce' => $this->table('catalog_category_entity')], 'ce.entity_id = ccp.category_id', [])
            ->joinInner(
                ['pc' => $this->categoryCounts($subtree)],
                'pc.product_id = i.product_id',
                []
            )
            ->columns([
                'category_id' => 'ce.entity_id',
                'path' => 'ce.path',
                'currency' => 'o.base_currency_code',
                'orders' => 'COUNT(DISTINCT o.entity_id)',
                'units' => sprintf('SUM(i.qty_ordered * %s)', $weight),
                'revenue' => sprintf('SUM(i.base_row_total * %s)', $weight),
                'discount' => sprintf('SUM(i.base_discount_amount * %s)', $weight),
            ])
            ->where('ce.level >= ?', self::MIN_LEVEL)
            ->group(['ce.entity_id', 'o.base_currency_code'])
            ->order('revenue DESC')
            ->limit($limit);

        if ($subtree !== null) {
            $select->where('ce.path LIKE ?', $subtree . '%');
        }

        return $this->connection()->fetchAll($select);
    }

    /**
     * Revenue of every top-level line in the period, per currency.
     *
     * What a split allocation reconciles to: the categories sum to this, less
     * whatever was sold from products in no category at all.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return array<int, array<string, mixed>>
     */
    public function lineTotals(Period $period, ?int $storeId): array
    {
        $select = $this->lines($period, $storeId)
            ->columns([
                'currency' => 'o.base_currency_code',
                'revenue' => 'SUM(i.base_row_total)',
            ])
            ->group('o.base_currency_code');

        return $this->connection()->fetchAll($select);
    }

    /**
     * Top-level, non-canceled order lines in the period.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return Select
     */
    private function lines(Period $period, ?int $storeId): Select
    {
        $select = $this->connection()->select()
            ->from(['i' => $this->table('sales_order_item')], [])
            ->joinInner(['o' => $this->table('sales_order')], 'o.entity_id = i.order_id', [])
            ->where('i.parent_item_id IS NULL')
            ->where('o.state <> ?', 'canceled')
            ->where('o.created_at >= ?', $period->getUtcFrom())
            ->where('o.created_at <= ?', $period->getUtcTo());

        if ($storeId !== null) {
            $select->where('o.store_id = ?', $storeId);
        }

        return $select;
    }

    /**
     * How many of the counted categories each product sits in — the divisor
     * of a split. Counted over the same categories the report covers, so a
     * subtree's figures reconcile to that subtree's sales.
     *
     * @param string|null $subtree
     * @return Select
     */
    private function categoryCounts(?string $subtree): Select
    {
        $select = $this->connection()->select()
            ->from(['ccp2' => $this->table('catalog_category_product')], [])
            ->joinInner(['ce2' => $this->table('catalog_category_entity')], 'ce2.entity_id = ccp2.category_id', [])
            ->columns(['product_id' => 'ccp2.product_id', 'category_count' => 'COUNT(*)'])
            ->where('ce2.level >= ?', self::MIN_LEVEL)
            ->group('ccp2.product_id');

        if ($subtree !== null) {
            $select->where('ce2.path LIKE ?', $subtree . '%');
        }

        return $select;
    }

    /**
     * The path prefix every descendant of a category shares: "1/2/7/".
     *
     * @param int $categoryId
     * @return string
     * @throws LocalizedException
     */
    private function subtreePrefix(int $categoryId): string
    {
        $path = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from(['ce' => $this->table('catalog_category_entity')], ['path'])
                ->where('ce.entity_id = ?', $categoryId)
        );

        if (!is_string($path) || $path === '') {
            throw new LocalizedException(__('There is no category with id %1.', $categoryId));
        }

        return $path . '/';
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
