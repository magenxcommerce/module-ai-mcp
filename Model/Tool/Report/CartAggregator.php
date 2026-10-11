<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Carts left with something in them.
 *
 * A quote stays `is_active` until it becomes an order, so an active quote
 * with items that nobody has touched for a while is an abandoned cart.
 * Inactivity is measured on `updated_at`, which Magento moves on every change
 * to the cart. Money is the quote's base subtotal, grouped by base currency;
 * a cart whose totals were never collected reports zero there.
 */
class CartAggregator
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Count and value of the abandoned carts, per currency.
     *
     * @param string $idleSince UTC; carts last touched at or before this.
     * @param string $notBefore UTC; carts last touched before this are too old to count.
     * @param int|null $storeId
     * @param bool|null $hasEmail True for carts with an address to write to, false for without.
     * @return array<int, array<string, mixed>>
     */
    public function summary(string $idleSince, string $notBefore, ?int $storeId, ?bool $hasEmail): array
    {
        $select = $this->abandoned($idleSince, $notBefore, $storeId, $hasEmail)
            ->columns([
                'currency' => 'q.base_currency_code',
                'carts' => 'COUNT(*)',
                'subtotal' => 'SUM(q.base_subtotal)',
                'items' => 'SUM(q.items_qty)',
                'with_email' => "SUM(CASE WHEN q.customer_email IS NOT NULL AND q.customer_email <> '' "
                    . 'THEN 1 ELSE 0 END)',
                'registered' => 'SUM(CASE WHEN q.customer_id IS NOT NULL THEN 1 ELSE 0 END)',
            ])
            ->group('q.base_currency_code');

        return $this->connection()->fetchAll($select);
    }

    /**
     * The most valuable abandoned carts, one row each.
     *
     * @param string $idleSince
     * @param string $notBefore
     * @param int|null $storeId
     * @param bool|null $hasEmail
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function carts(string $idleSince, string $notBefore, ?int $storeId, ?bool $hasEmail, int $limit): array
    {
        $select = $this->abandoned($idleSince, $notBefore, $storeId, $hasEmail)
            ->columns([
                'cart_id' => 'q.entity_id',
                'store_id' => 'q.store_id',
                'customer_id' => 'q.customer_id',
                'customer_email' => 'q.customer_email',
                'created_at' => 'q.created_at',
                'updated_at' => 'q.updated_at',
                'items_count' => 'q.items_count',
                'items_qty' => 'q.items_qty',
                'subtotal' => 'q.base_subtotal',
                'currency' => 'q.base_currency_code',
            ])
            ->order('q.base_subtotal DESC')
            ->limit($limit);

        return $this->connection()->fetchAll($select);
    }

    /**
     * @param string $idleSince
     * @param string $notBefore
     * @param int|null $storeId
     * @param bool|null $hasEmail
     * @return Select
     */
    private function abandoned(string $idleSince, string $notBefore, ?int $storeId, ?bool $hasEmail): Select
    {
        $select = $this->connection()->select()
            ->from(['q' => $this->resource->getTableName('quote')], [])
            ->where('q.is_active = ?', 1)
            ->where('q.items_count > ?', 0)
            ->where('q.updated_at <= ?', $idleSince)
            ->where('q.updated_at >= ?', $notBefore);

        if ($storeId !== null) {
            $select->where('q.store_id = ?', $storeId);
        }
        if ($hasEmail === true) {
            $select->where("q.customer_email IS NOT NULL AND q.customer_email <> ''");
        } elseif ($hasEmail === false) {
            $select->where("q.customer_email IS NULL OR q.customer_email = ''");
        }

        return $select;
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
