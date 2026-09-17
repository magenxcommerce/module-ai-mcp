<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

/**
 * Aggregate rows into the figures a report reports.
 *
 * Pure, and deliberately so: this is where every derived number is computed —
 * net revenue, average order value — and derived numbers are what a merchant
 * quotes. Keeping them out of the SQL means they can be pinned by a test that
 * does not need a database.
 *
 * Money is cast the way {@see \Magenx\AiMcp\Model\Tool\Sales\OrderProjector}
 * casts it, for the same reason: MySQL hands back "1234.5600" and a model reads
 * 1234.56 more reliably. Unlike that projector, a missing aggregate becomes 0.0
 * rather than null — SUM over no rows is genuinely zero here, not unknown.
 */
class TotalsProjector
{
    /**
     * One currency's block of figures.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toTotals(array $row): array
    {
        $orders = (int) ($row['orders'] ?? 0);
        $ordered = $this->money($row['ordered_total'] ?? null);
        $invoiced = $this->money($row['invoiced_total'] ?? null);
        $refunded = $this->money($row['refunded_total'] ?? null);

        return [
            'currency' => $row['currency'] ?? null,
            'orders' => $orders,
            // What was ordered, what was actually taken, and what went back.
            // Reported together because each is the honest answer to a
            // different question, and picking one to call "revenue" answers the
            // other two wrongly.
            'ordered_total' => $ordered,
            'invoiced_total' => $invoiced,
            'refunded_total' => $refunded,
            'net_total' => round($invoiced - $refunded, 4),
            'tax_total' => $this->money($row['tax_total'] ?? null),
            'shipping_total' => $this->money($row['shipping_total'] ?? null),
            'discount_total' => $this->money($row['discount_total'] ?? null),
            'average_order_value' => $orders > 0 ? round($ordered / $orders, 4) : null,
            'canceled_orders' => (int) ($row['canceled_orders'] ?? 0),
            'canceled_total' => $this->money($row['canceled_total'] ?? null),
        ];
    }

    /**
     * Every currency's block, for a whole period.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function toCurrencyTotals(array $rows): array
    {
        return array_map(fn (array $row): array => $this->toTotals($row), $rows);
    }

    /**
     * Bucketed rows, regrouped so each bucket carries its currencies.
     *
     * The query groups by bucket *and* currency, so a multi-currency store
     * returns several rows per bucket. Flattening that into one row per bucket
     * would mean adding unlike money together, which is the mistake this whole
     * group of tools is arranged to avoid.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function toBuckets(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = (string) ($row['bucket'] ?? '');
            $buckets[$key] ??= ['bucket' => $key, 'totals' => []];
            $buckets[$key]['totals'][] = $this->toTotals($row);
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * @param mixed $value
     * @return float
     */
    private function money(mixed $value): float
    {
        return $value === null ? 0.0 : (float) $value;
    }
}
