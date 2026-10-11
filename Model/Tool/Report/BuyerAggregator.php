<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\App\ResourceConnection;

/**
 * New, returning and inactive buyers.
 *
 * "New" needs each buyer's first order ever, which means reading their whole
 * history rather than the period — the reason customer_summary never offered
 * it. It is still one aggregate: an inner GROUP BY per buyer produces first
 * order, last order and the period's orders and revenue, and an outer one
 * counts the buyers into segments. No row reaches PHP but the totals.
 *
 * A buyer is an e-mail address, lower-cased, so a guest checkout and a later
 * account with the same address are one person — the same choice
 * customer_summary makes. History is read up to the end of the period, so a
 * report on last year is not changed by this year's orders. Canceled orders
 * count for nothing.
 */
class BuyerAggregator
{
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
     * @param string $inactiveBefore UTC time; a buyer whose last order is older is inactive.
     * @return array<int, array<string, mixed>>
     */
    public function segments(Period $period, ?int $storeId, string $inactiveBefore): array
    {
        $connection = $this->connection();
        $inPeriod = $connection->quoteInto('o.created_at >= ?', $period->getUtcFrom());

        $buyers = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')], [])
            ->columns([
                'buyer' => 'LOWER(o.customer_email)',
                'currency' => 'o.base_currency_code',
                'first_order_at' => 'MIN(o.created_at)',
                'last_order_at' => 'MAX(o.created_at)',
                'period_orders' => sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $inPeriod),
                'period_revenue' => sprintf('SUM(CASE WHEN %s THEN o.base_grand_total ELSE 0 END)', $inPeriod),
            ])
            ->where('o.created_at <= ?', $period->getUtcTo())
            ->where('o.state <> ?', 'canceled')
            ->where("o.customer_email IS NOT NULL AND o.customer_email <> ''")
            ->group(['LOWER(o.customer_email)', 'o.base_currency_code']);

        if ($storeId !== null) {
            $buyers->where('o.store_id = ?', $storeId);
        }

        $isNew = $connection->quoteInto('b.period_orders > 0 AND b.first_order_at >= ?', $period->getUtcFrom());
        $isReturning = $connection->quoteInto('b.period_orders > 0 AND b.first_order_at < ?', $period->getUtcFrom());
        $isInactive = $connection->quoteInto('b.last_order_at < ?', $inactiveBefore);

        $select = $connection->select()
            ->from(['b' => $buyers], [])
            ->columns([
                'currency' => 'b.currency',
                'known_buyers' => 'COUNT(*)',
                'new_buyers' => sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $isNew),
                'new_orders' => sprintf('SUM(CASE WHEN %s THEN b.period_orders ELSE 0 END)', $isNew),
                'new_revenue' => sprintf('SUM(CASE WHEN %s THEN b.period_revenue ELSE 0 END)', $isNew),
                'returning_buyers' => sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $isReturning),
                'returning_orders' => sprintf('SUM(CASE WHEN %s THEN b.period_orders ELSE 0 END)', $isReturning),
                'returning_revenue' => sprintf('SUM(CASE WHEN %s THEN b.period_revenue ELSE 0 END)', $isReturning),
                'inactive_buyers' => sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $isInactive),
            ])
            ->group('b.currency');

        return $connection->fetchAll($select);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
