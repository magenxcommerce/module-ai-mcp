<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\Report\Period;
use Magenx\AiMcp\Model\Tool\Report\PeriodResolver;
use Magenx\AiMcp\Model\Tool\Report\SalesAggregator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The only SQL in the module, and the three decisions inside it.
 *
 * A stubbed Select cannot tell us what the rendered query does, so this does
 * not pretend to test the SQL. What it pins is narrower and still worth
 * pinning: that the three clauses whose absence produces a confident wrong
 * number are actually asked for — the canceled split, the currency grouping,
 * and the parent-item filter that stops a configurable product being counted
 * twice. Each of those failing silently returns a plausible figure.
 *
 * @see SalesAggregator
 */
class SalesAggregatorTest extends TestCase
{
    private Select&MockObject $select;
    private AdapterInterface&MockObject $connection;
    private SalesAggregator $aggregator;

    /** @var array<int, string> Column expressions passed to columns(). */
    private array $columns = [];

    /** @var array<int, mixed> Conditions passed to where(). */
    private array $conditions = [];

    /** @var array<int, mixed> Groupings passed to group(). */
    private array $groups = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('joinInner')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();
        $this->select->method('columns')->willReturnCallback(function ($cols) {
            foreach ((array) $cols as $key => $expression) {
                $this->columns[is_string($key) ? $key : (string) $expression] = (string) $expression;
            }

            return $this->select;
        });
        $this->select->method('where')->willReturnCallback(function ($cond, $value = null) {
            $this->conditions[] = $value === null ? $cond : $cond . ' => ' . $value;

            return $this->select;
        });
        $this->select->method('group')->willReturnCallback(function ($spec) {
            foreach ((array) $spec as $one) {
                $this->groups[] = (string) $one;
            }

            return $this->select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('quote')->willReturnCallback(
            static fn ($value): string => "'" . $value . "'"
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->aggregator = new SalesAggregator($resource);
    }

    /**
     * Money comes from the base_* columns, never the order-currency ones. The
     * order-currency columns are what every other projector in the module
     * reports, so reaching for them here would look right and add unlike money.
     *
     * @return void
     */
    public function testTotalsReadTheBaseCurrencyColumns(): void
    {
        $this->aggregator->totals($this->period(), null);

        $this->assertStringContainsString('o.base_grand_total', $this->columns['ordered_total']);
        $this->assertStringContainsString('o.base_total_invoiced', $this->columns['invoiced_total']);
        $this->assertStringContainsString('o.base_total_refunded', $this->columns['refunded_total']);
        $this->assertSame('o.base_currency_code', $this->columns['currency']);
    }

    /**
     * @return void
     */
    public function testTotalsAreGroupedByCurrency(): void
    {
        $this->aggregator->totals($this->period(), null);

        $this->assertContains('o.base_currency_code', $this->groups);
    }

    /**
     * Canceled orders are kept out of the headline figures and reported on
     * their own, rather than being counted or dropped without saying so.
     *
     * @return void
     */
    public function testCanceledOrdersAreExcludedFromTheTotalsAndCountedSeparately(): void
    {
        $this->aggregator->totals($this->period(), null);

        $this->assertStringContainsString("o.state = 'canceled' THEN 0", $this->columns['ordered_total']);
        $this->assertStringContainsString("o.state = 'canceled' THEN 1", $this->columns['canceled_orders']);
        $this->assertStringContainsString(
            "o.state = 'canceled' THEN o.base_grand_total",
            $this->columns['canceled_total']
        );
    }

    /**
     * @return void
     */
    public function testThePeriodBoundsTheQueryInUtc(): void
    {
        $this->aggregator->totals($this->period(), null);

        $this->assertContains('o.created_at >= ? => 2026-02-28 23:00:00', $this->conditions);
        $this->assertContains('o.created_at <= ? => 2026-03-31 21:59:59', $this->conditions);
    }

    /**
     * @return void
     */
    public function testNoStoreFilterIsAppliedWhenNoneIsAskedFor(): void
    {
        $this->aggregator->totals($this->period(), null);

        foreach ($this->conditions as $condition) {
            $this->assertStringNotContainsString('store_id', (string) $condition);
        }
    }

    /**
     * @return void
     */
    public function testAStoreFilterIsAppliedWhenOneIsGiven(): void
    {
        $this->aggregator->totals($this->period(), 3);

        $this->assertContains('o.store_id = ? => 3', $this->conditions);
    }

    /**
     * The single most expensive mistake available here: a configurable product
     * writes both itself and its simple variant to sales_order_item, so a
     * bestseller list that counts every row doubles every configurable sold.
     *
     * @return void
     */
    public function testProductFiguresCountOnlyTopLevelOrderLines(): void
    {
        $this->aggregator->topProducts($this->period(), null, false, 10);

        $this->assertContains('i.parent_item_id IS NULL', $this->conditions);
    }

    /**
     * @return void
     */
    public function testProductsAreGroupedBySkuAndCurrency(): void
    {
        $this->aggregator->topProducts($this->period(), null, false, 10);

        $this->assertContains('i.sku', $this->groups);
        $this->assertContains('o.base_currency_code', $this->groups);
    }

    /**
     * @return void
     */
    public function testBucketingConvertsToTheStoresOffsetRatherThanANamedZone(): void
    {
        $this->aggregator->totalsByBucket($this->period(PeriodResolver::GRANULARITY_DAY), null);

        // A named zone would need MySQL's timezone tables loaded, and returns
        // NULL rather than failing when they are not — collapsing every bucket
        // into one.
        $this->assertStringContainsString("CONVERT_TZ(o.created_at, '+00:00', '+01:00')", $this->columns['bucket']);
        $this->assertStringStartsWith('DATE(', $this->columns['bucket']);
    }

    /**
     * Weeks start Monday via WEEKDAY(), which does not depend on the server's
     * first-day-of-week setting the way WEEK() does.
     *
     * @return void
     */
    public function testWeeklyBucketsStartOnMondayIndependentOfServerSettings(): void
    {
        $this->aggregator->totalsByBucket($this->period(PeriodResolver::GRANULARITY_WEEK), null);

        $this->assertStringContainsString('WEEKDAY(', $this->columns['bucket']);
        $this->assertStringNotContainsString('WEEK(', str_replace('WEEKDAY(', '', $this->columns['bucket']));
    }

    /**
     * @return void
     */
    public function testMonthlyBucketsCollapseToTheFirstOfTheMonth(): void
    {
        $this->aggregator->totalsByBucket($this->period(PeriodResolver::GRANULARITY_MONTH), null);

        $this->assertStringContainsString("'%Y-%m-01'", $this->columns['bucket']);
    }

    /**
     * Guest checkouts are people too — counting customers by id would report
     * every guest order as the same null customer.
     *
     * @return void
     */
    public function testCustomersAreCountedByEmailNotById(): void
    {
        $this->aggregator->customerTotals($this->period(), null);

        $this->assertSame('COUNT(DISTINCT o.customer_email)', $this->columns['customers']);
    }

    /**
     * @param string $granularity
     * @return Period
     */
    private function period(string $granularity = PeriodResolver::GRANULARITY_NONE): Period
    {
        return new Period(
            'Europe/Berlin',
            '2026-03-01 00:00:00',
            '2026-03-31 23:59:59',
            '2026-02-28 23:00:00',
            '2026-03-31 21:59:59',
            $granularity,
            3600
        );
    }
}
