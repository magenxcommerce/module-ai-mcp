<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * The only SQL in this module.
 *
 * Everything else reads through a repository or a collection, which is right
 * for the tools that return records. It is wrong here: a period total means
 * SUM and GROUP BY, and the alternative — fetching orders and adding them up in
 * PHP — is exactly the "pull rows into a model's context" this server exists to
 * avoid, and is capped at 100 rows a page anyway.
 *
 * Magento's own aggregate tables were the other option and are not used: they
 * are filled by a nightly cron, so they lag a day, and on a store that has
 * never refreshed statistics they are empty — which would report zero revenue
 * for a store with orders, confidently.
 *
 * Three things here are load-bearing rather than incidental, and each has a
 * test:
 *
 *  - Money is read from the `base_*` columns, never the order-currency ones,
 *    and every query groups by `base_currency_code`. Adding 100 EUR to 100 USD
 *    produces 200 of nothing.
 *  - Canceled orders are separated rather than silently included or silently
 *    dropped, because either choice is a number somebody will act on.
 *  - Product figures come only from rows with no parent item. A configurable
 *    product writes both itself and its simple child to `sales_order_item`, so
 *    summing every row doubles the revenue of every configurable sold.
 */
class SalesAggregator
{
    /** Magento's own name for an order that was called off. */
    private const STATE_CANCELED = 'canceled';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Period totals, one row per base currency.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return array<int, array<string, mixed>>
     */
    public function totals(Period $period, ?int $storeId): array
    {
        $select = $this->orders($period, $storeId)
            ->columns($this->totalColumns())
            ->group('o.base_currency_code');

        return $this->fetch($select);
    }

    /**
     * The same totals, bucketed by day, week or month in the store's calendar.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return array<int, array<string, mixed>>
     */
    public function totalsByBucket(Period $period, ?int $storeId): array
    {
        $bucket = $this->bucketExpression($period);

        $select = $this->orders($period, $storeId)
            ->columns(['bucket' => $bucket] + $this->totalColumns())
            ->group([$bucket, 'o.base_currency_code'])
            ->order('bucket ASC');

        return $this->fetch($select);
    }

    /**
     * Counts and value grouped by order status, for triage.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return array<int, array<string, mixed>>
     */
    public function statusBreakdown(Period $period, ?int $storeId): array
    {
        $select = $this->orders($period, $storeId)
            ->columns([
                'status' => 'o.status',
                'state' => 'o.state',
                'currency' => 'o.base_currency_code',
                'orders' => 'COUNT(*)',
                'ordered_total' => 'SUM(o.base_grand_total)',
            ])
            ->group(['o.status', 'o.state', 'o.base_currency_code'])
            ->order('orders DESC');

        return $this->fetch($select);
    }

    /**
     * Bestsellers over the period.
     *
     * @param Period $period
     * @param int|null $storeId
     * @param bool $byRevenue Rank by money rather than units.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(Period $period, ?int $storeId, bool $byRevenue, int $limit): array
    {
        $select = $this->connection()->select()
            ->from(['i' => $this->table('sales_order_item')], [])
            ->joinInner(['o' => $this->table('sales_order')], 'o.entity_id = i.order_id', [])
            ->columns([
                'sku' => 'i.sku',
                'name' => 'MAX(i.name)',
                'currency' => 'o.base_currency_code',
                'qty' => 'SUM(i.qty_ordered)',
                // base_row_total is net of tax, which is the figure a merchant
                // compares products on.
                'revenue' => 'SUM(i.base_row_total)',
                'orders' => 'COUNT(DISTINCT i.order_id)',
            ])
            // The whole reason a bestseller list can be wrong: a configurable
            // product writes a parent row and a child row for the same sale.
            ->where('i.parent_item_id IS NULL')
            ->group(['i.sku', 'o.base_currency_code'])
            ->order(($byRevenue ? 'revenue' : 'qty') . ' DESC')
            ->limit($limit);

        $this->restrict($select, $period, $storeId);

        return $this->fetch($select);
    }

    /**
     * Who bought, and who spent the most.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return array<int, array<string, mixed>>
     */
    public function customerTotals(Period $period, ?int $storeId): array
    {
        $select = $this->orders($period, $storeId)
            ->columns([
                'currency' => 'o.base_currency_code',
                'customers' => 'COUNT(DISTINCT o.customer_email)',
                'guest_orders' => 'SUM(CASE WHEN o.customer_is_guest = 1 THEN 1 ELSE 0 END)',
                'account_orders' => 'SUM(CASE WHEN o.customer_is_guest = 1 THEN 0 ELSE 1 END)',
            ])
            ->group('o.base_currency_code');

        return $this->fetch($select);
    }

    /**
     * The biggest spenders in the period.
     *
     * Keyed on e-mail rather than customer id so a guest checkout counts as the
     * person it was, not as a null.
     *
     * @param Period $period
     * @param int|null $storeId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function topCustomers(Period $period, ?int $storeId, int $limit): array
    {
        $select = $this->orders($period, $storeId)
            ->columns([
                'customer_email' => 'o.customer_email',
                'customer_id' => 'MAX(o.customer_id)',
                'currency' => 'o.base_currency_code',
                'orders' => 'COUNT(*)',
                'ordered_total' => 'SUM(o.base_grand_total)',
            ])
            ->group(['o.customer_email', 'o.base_currency_code'])
            ->order('ordered_total DESC')
            ->limit($limit);

        return $this->fetch($select);
    }

    /**
     * The aggregate columns shared by the period and bucket queries.
     *
     * Canceled orders are split out rather than included or dropped: a total
     * that quietly counts them overstates revenue, and one that quietly omits
     * them hides a cancellation spike. Both are reported, and the caller adds
     * them if it means to.
     *
     * @return array<string, string>
     */
    private function totalColumns(): array
    {
        return [
            'currency' => 'o.base_currency_code',
            'orders' => $this->sumLive('1'),
            'ordered_total' => $this->sumLive('o.base_grand_total'),
            'invoiced_total' => $this->sumLive('o.base_total_invoiced'),
            'refunded_total' => $this->sumLive('o.base_total_refunded'),
            'tax_total' => $this->sumLive('o.base_tax_amount'),
            'shipping_total' => $this->sumLive('o.base_shipping_amount'),
            'discount_total' => $this->sumLive('o.base_discount_amount'),
            'canceled_orders' => $this->sumCanceled('1'),
            'canceled_total' => $this->sumCanceled('o.base_grand_total'),
        ];
    }

    /**
     * Sum an expression over the orders that were not called off.
     *
     * @param string $expression
     * @return string
     */
    private function sumLive(string $expression): string
    {
        return sprintf(
            "SUM(CASE WHEN o.state = '%s' THEN 0 ELSE %s END)",
            self::STATE_CANCELED,
            $expression
        );
    }

    /**
     * @param string $expression
     * @return string
     */
    private function sumCanceled(string $expression): string
    {
        return sprintf(
            "SUM(CASE WHEN o.state = '%s' THEN %s ELSE 0 END)",
            self::STATE_CANCELED,
            $expression
        );
    }

    /**
     * A Select over sales_order already bounded to the period and store.
     *
     * @param Period $period
     * @param int|null $storeId
     * @return Select
     */
    private function orders(Period $period, ?int $storeId): Select
    {
        $select = $this->connection()->select()->from(['o' => $this->table('sales_order')], []);
        $this->restrict($select, $period, $storeId);

        return $select;
    }

    /**
     * @param Select $select
     * @param Period $period
     * @param int|null $storeId
     * @return void
     */
    private function restrict(Select $select, Period $period, ?int $storeId): void
    {
        $select->where('o.created_at >= ?', $period->getUtcFrom());
        $select->where('o.created_at <= ?', $period->getUtcTo());

        if ($storeId !== null) {
            $select->where('o.store_id = ?', $storeId);
        }
    }

    /**
     * The bucket key, in the store's calendar rather than UTC.
     *
     * CONVERT_TZ takes the numeric offset in effect when the period began. A
     * range crossing a daylight-saving change therefore buckets the hour around
     * the transition into the neighbouring day — the same approximation
     * Magento's own reports make. The period *total* is unaffected: its
     * boundaries are computed in PHP from the real timezone.
     *
     * @param Period $period
     * @return string
     */
    private function bucketExpression(Period $period): string
    {
        $local = sprintf(
            "CONVERT_TZ(o.created_at, '+00:00', %s)",
            $this->connection()->quote($period->getOffsetForSql())
        );

        return match ($period->getGranularity()) {
            PeriodResolver::GRANULARITY_MONTH => sprintf("DATE_FORMAT(%s, '%%Y-%%m-01')", $local),
            // Weeks start on Monday: WEEKDAY() is 0 for Monday regardless of
            // the server's default first-day-of-week setting, which WEEK() is
            // not.
            PeriodResolver::GRANULARITY_WEEK => sprintf(
                'DATE(DATE_SUB(%s, INTERVAL WEEKDAY(%s) DAY))',
                $local,
                $local
            ),
            default => sprintf('DATE(%s)', $local),
        };
    }

    /**
     * @param Select $select
     * @return array<int, array<string, mixed>>
     */
    private function fetch(Select $select): array
    {
        return $this->connection()->fetchAll($select);
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
