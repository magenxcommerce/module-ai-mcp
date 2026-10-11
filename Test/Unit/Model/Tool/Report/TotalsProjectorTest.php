<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\Report\TotalsProjector;
use PHPUnit\Framework\TestCase;

/**
 * Turning aggregate rows into the figures a merchant would quote.
 *
 * Every derived number lives here rather than in the SQL so it can be pinned
 * without a database, and each of them is one somebody acts on: an average
 * order value, a net after refunds, a per-currency split that must not be
 * flattened. The arithmetic is trivial; being wrong about it is not.
 *
 * @see TotalsProjector
 */
class TotalsProjectorTest extends TestCase
{
    private TotalsProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->projector = new TotalsProjector();
    }

    /**
     * @return void
     */
    public function testNetIsWhatWasInvoicedLessWhatWentBack(): void
    {
        $totals = $this->projector->toTotals([
            'orders' => '10',
            'ordered_total' => '1000.0000',
            'invoiced_total' => '900.0000',
            'refunded_total' => '150.0000',
        ]);

        $this->assertSame(750.0, $totals['net_total']);
    }

    /**
     * Ordered, invoiced and refunded each answer a different question, and a
     * report that collapsed them into one "revenue" would answer two wrongly.
     *
     * @return void
     */
    public function testAllThreeRevenueReadingsSurvive(): void
    {
        $totals = $this->projector->toTotals([
            'orders' => '3',
            'ordered_total' => '300.0000',
            'invoiced_total' => '200.0000',
            'refunded_total' => '50.0000',
        ]);

        $this->assertSame(300.0, $totals['ordered_total']);
        $this->assertSame(200.0, $totals['invoiced_total']);
        $this->assertSame(50.0, $totals['refunded_total']);
    }

    /**
     * A period with no orders must not divide by zero, and must not claim an
     * average of nothing is zero — there is no average.
     *
     * @return void
     */
    public function testAnEmptyPeriodHasNoAverageOrderValue(): void
    {
        $totals = $this->projector->toTotals(['orders' => '0', 'ordered_total' => null]);

        $this->assertSame(0, $totals['orders']);
        $this->assertNull($totals['average_order_value']);
        $this->assertSame(0.0, $totals['ordered_total']);
    }

    /**
     * @return void
     */
    public function testAverageOrderValueIsOrderedOverCount(): void
    {
        $totals = $this->projector->toTotals(['orders' => '4', 'ordered_total' => '250.0000']);

        $this->assertSame(62.5, $totals['average_order_value']);
    }

    /**
     * MySQL hands back "1234.5600"; a model reads 1234.56.
     *
     * @return void
     */
    public function testMoneyArrivesAsANumberNotAString(): void
    {
        $totals = $this->projector->toTotals(['orders' => '1', 'tax_total' => '19.9900']);

        $this->assertSame(19.99, $totals['tax_total']);
    }

    /**
     * Canceled orders are carried, not hidden — a total that omits them without
     * saying so hides a cancellation spike.
     *
     * @return void
     */
    public function testCanceledFiguresAreReportedAlongsideTheLiveOnes(): void
    {
        $totals = $this->projector->toTotals([
            'orders' => '8',
            'ordered_total' => '800.0000',
            'canceled_orders' => '2',
            'canceled_total' => '175.0000',
        ]);

        $this->assertSame(8, $totals['orders']);
        $this->assertSame(2, $totals['canceled_orders']);
        $this->assertSame(175.0, $totals['canceled_total']);
    }

    /**
     * The whole reason the query groups by currency.
     *
     * @return void
     */
    public function testEachCurrencyKeepsItsOwnBlock(): void
    {
        $totals = $this->projector->toCurrencyTotals([
            ['currency' => 'EUR', 'orders' => '2', 'ordered_total' => '100.0000'],
            ['currency' => 'USD', 'orders' => '3', 'ordered_total' => '300.0000'],
        ]);

        $this->assertCount(2, $totals);
        $this->assertSame('EUR', $totals[0]['currency']);
        $this->assertSame(100.0, $totals[0]['ordered_total']);
        $this->assertSame('USD', $totals[1]['currency']);
    }

    /**
     * A bucket holding two currencies keeps them apart too. Flattening here
     * would add 100 EUR to 100 USD and report 200 of nothing.
     *
     * @return void
     */
    public function testABucketHoldsOneBlockPerCurrencyRatherThanASum(): void
    {
        $buckets = $this->projector->toBuckets([
            ['bucket' => '2026-03-01', 'currency' => 'EUR', 'orders' => '1', 'ordered_total' => '100.0000'],
            ['bucket' => '2026-03-01', 'currency' => 'USD', 'orders' => '1', 'ordered_total' => '100.0000'],
            ['bucket' => '2026-03-02', 'currency' => 'EUR', 'orders' => '2', 'ordered_total' => '250.0000'],
        ]);

        $this->assertCount(2, $buckets);
        $this->assertSame('2026-03-01', $buckets[0]['bucket']);
        $this->assertCount(2, $buckets[0]['totals']);
        $this->assertSame(['EUR', 'USD'], array_column($buckets[0]['totals'], 'currency'));
        $this->assertCount(1, $buckets[1]['totals']);
    }

    /**
     * A trend read in the wrong order is not a trend.
     *
     * @return void
     */
    public function testBucketsComeBackOldestFirst(): void
    {
        $buckets = $this->projector->toBuckets([
            ['bucket' => '2026-03-03', 'currency' => 'EUR', 'orders' => '1'],
            ['bucket' => '2026-03-01', 'currency' => 'EUR', 'orders' => '1'],
            ['bucket' => '2026-03-02', 'currency' => 'EUR', 'orders' => '1'],
        ]);

        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($buckets, 'bucket'));
    }

    /**
     * Refunds by refund date replace the order-dated ones, and a currency that
     * only had refunds still appears: money left the store in it.
     *
     * @return void
     */
    public function testRefundsByRefundDateReplaceTheOrderDatedOnes(): void
    {
        $rows = $this->projector->withRefundsByRefundDate(
            [
                ['currency' => 'EUR', 'orders' => '2', 'invoiced_total' => '100', 'refunded_total' => '40'],
                ['currency' => 'USD', 'orders' => '1', 'invoiced_total' => '50', 'refunded_total' => '10'],
            ],
            [
                ['currency' => 'EUR', 'refunded_total' => '15'],
                ['currency' => 'GBP', 'refunded_total' => '5'],
            ]
        );
        $totals = array_column($this->projector->toCurrencyTotals($rows), null, 'currency');

        $this->assertSame(15.0, $totals['EUR']['refunded_total']);
        $this->assertSame(85.0, $totals['EUR']['net_total']);
        $this->assertSame(0.0, $totals['USD']['refunded_total']);
        $this->assertSame(0, $totals['GBP']['orders']);
        $this->assertSame(-5.0, $totals['GBP']['net_total']);
    }

    /**
     * @return void
     */
    public function testBucketedRefundsAreMatchedOnBucketAndCurrency(): void
    {
        $rows = $this->projector->withRefundsByRefundDate(
            [['bucket' => '2026-03-01', 'currency' => 'EUR', 'orders' => '1']],
            [['bucket' => '2026-03-02', 'currency' => 'EUR', 'refunded_total' => '7']]
        );
        $buckets = $this->projector->toBuckets($rows);

        $this->assertSame(['2026-03-01', '2026-03-02'], array_column($buckets, 'bucket'));
        $this->assertSame(0.0, $buckets[0]['totals'][0]['refunded_total']);
        $this->assertSame(7.0, $buckets[1]['totals'][0]['refunded_total']);
    }

    /**
     * @return void
     */
    public function testAChangeReportsBothTheDifferenceAndThePercentage(): void
    {
        $change = $this->projector->change(
            [$this->projector->toTotals(['currency' => 'EUR', 'orders' => '12', 'ordered_total' => '1200'])],
            [$this->projector->toTotals(['currency' => 'EUR', 'orders' => '10', 'ordered_total' => '800'])]
        );

        $this->assertSame('EUR', $change[0]['currency']);
        $this->assertSame(['change' => 2.0, 'percent' => 20.0], $change[0]['orders']);
        $this->assertSame(['change' => 400.0, 'percent' => 50.0], $change[0]['ordered_total']);
    }

    /**
     * Growth from nothing has no percentage; inventing 100% would be quoted.
     *
     * @return void
     */
    public function testAChangeFromZeroHasNoPercentage(): void
    {
        $change = $this->projector->change(
            [$this->projector->toTotals(['currency' => 'USD', 'orders' => '3', 'ordered_total' => '90'])],
            []
        );

        $this->assertSame(['change' => 3.0, 'percent' => null], $change[0]['orders']);
    }
}
