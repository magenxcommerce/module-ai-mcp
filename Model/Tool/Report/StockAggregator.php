<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\App\ResourceConnection;

/**
 * How many days of stock each selling product has left.
 *
 * Velocity is units sold over a recent window, divided by its length; days
 * left is the quantity on hand divided by that. Units are counted on the lines
 * that carry stock — simple, virtual and downloadable — so a configurable
 * sale is counted against the variant that left the shelf, not the parent
 * that has no quantity of its own. Canceled quantity is taken off.
 *
 * Quantity is the legacy single-stock figure in `cataloginventory_stock_item`
 * — the same one get_stock and update_stock read and write. On a store using
 * MSI that is the default source only.
 *
 * A product whose stock is not managed has no meaningful quantity and is left
 * out. A row inheriting the setting from configuration is assumed managed,
 * which is Magento's default.
 */
class StockAggregator
{
    /** The product types whose order lines are the ones stock is taken from. */
    private const STOCKED_TYPES = ['simple', 'virtual', 'downloadable'];

    /** The legacy default stock: the only one cataloginventory_stock_item holds. */
    private const DEFAULT_STOCK_ID = 1;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Products running out within the threshold, soonest first.
     *
     * @param string $soldSince UTC start of the velocity window.
     * @param int $velocityDays Length of that window, in days.
     * @param float $thresholdDays Report products with at most this many days left.
     * @param int|null $storeId Count sales from one store only.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function atRisk(
        string $soldSince,
        int $velocityDays,
        float $thresholdDays,
        ?int $storeId,
        int $limit
    ): array {
        $connection = $this->connection();

        $sold = $connection->select()
            ->from(['i' => $this->table('sales_order_item')], [])
            ->joinInner(['o' => $this->table('sales_order')], 'o.entity_id = i.order_id', [])
            ->columns([
                'product_id' => 'i.product_id',
                'name' => 'MAX(i.name)',
                'units' => 'SUM(i.qty_ordered - i.qty_canceled)',
            ])
            ->where('o.created_at >= ?', $soldSince)
            ->where('o.state <> ?', 'canceled')
            ->where('i.product_type IN (?)', self::STOCKED_TYPES)
            ->group('i.product_id');

        if ($storeId !== null) {
            $sold->where('o.store_id = ?', $storeId);
        }

        $daysLeft = sprintf('(GREATEST(si.qty, 0) * %d / s.units)', $velocityDays);

        $select = $connection->select()
            ->from(['s' => $sold], [])
            ->joinInner(
                ['si' => $this->table('cataloginventory_stock_item')],
                $connection->quoteInto('si.product_id = s.product_id AND si.stock_id = ?', self::DEFAULT_STOCK_ID),
                []
            )
            ->joinInner(['e' => $this->table('catalog_product_entity')], 'e.entity_id = s.product_id', [])
            ->columns([
                'product_id' => 's.product_id',
                'sku' => 'e.sku',
                'name' => 's.name',
                'qty' => 'si.qty',
                'is_in_stock' => 'si.is_in_stock',
                'units_sold' => 's.units',
                'days_left' => $daysLeft,
            ])
            ->where('s.units > ?', 0)
            ->where('si.use_config_manage_stock = 1 OR si.manage_stock = 1')
            ->where($daysLeft . ' <= ?', $thresholdDays)
            ->order('days_left ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
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
