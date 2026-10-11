<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\Report\BuyerAggregator;
use Magenx\AiMcp\Model\Tool\Report\CartAggregator;
use Magenx\AiMcp\Model\Tool\Report\CategoryAggregator;
use Magenx\AiMcp\Model\Tool\Report\Period;
use Magenx\AiMcp\Model\Tool\Report\StockAggregator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The clauses in the newer report queries whose absence returns a plausible
 * wrong number.
 *
 * As in SalesAggregatorTest, a stubbed Select cannot say what the SQL does.
 * It can say which clauses were asked for: the split divisor, the root
 * category exclusion, the history bound on buyers, the idle window on carts,
 * the stocked product types. Subqueries share the one recording Select, so
 * what is pinned is that a clause is present somewhere in the query.
 */
class InsightAggregatorsTest extends TestCase
{
    /** @var array<string, string> */
    private array $columns = [];

    /** @var array<int, string> */
    private array $conditions = [];

    /** @var array<int, string> */
    private array $groups = [];

    /** @var array<int, string> */
    private array $joins = [];

    private ResourceConnection $resource;

    /** @var mixed What fetchOne answers. */
    private mixed $fetchOne = '1/2/7';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        foreach (['joinInner', 'joinLeft'] as $join) {
            $select->method($join)->willReturnCallback(function ($name, $cond) use ($select) {
                $this->joins[] = (string) $cond;

                return $select;
            });
        }
        $select->method('columns')->willReturnCallback(function ($cols) use ($select) {
            foreach ((array) $cols as $key => $expression) {
                $this->columns[(string) $key] = (string) $expression;
            }

            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->conditions[] = $value === null
                ? (string) $cond
                : $cond . ' => ' . (is_array($value) ? implode(',', $value) : $value);

            return $select;
        });
        $select->method('group')->willReturnCallback(function ($spec) use ($select) {
            foreach ((array) $spec as $one) {
                $this->groups[] = (string) $one;
            }

            return $select;
        });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchOne')->willReturnCallback(fn () => $this->fetchOne);
        $connection->method('quoteInto')->willReturnCallback(
            static fn ($text, $value): string => str_replace('?', "'" . $value . "'", $text)
        );

        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($connection);
        $this->resource->method('getTableName')->willReturnArgument(0);
    }

    /**
     * @return void
     */
    public function testASplitDividesEachLineByItsCategoryCount(): void
    {
        (new CategoryAggregator($this->resource))->categorySales($this->period(), null, 'split', null, 10);

        $this->assertSame('SUM(i.base_row_total * (1 / pc.category_count))', $this->columns['revenue']);
        $this->assertSame('COUNT(DISTINCT o.entity_id)', $this->columns['orders']);
        $this->assertContains('i.parent_item_id IS NULL', $this->conditions);
        $this->assertContains('o.state <> ? => canceled', $this->conditions);
    }

    /**
     * @return void
     */
    public function testAFullAllocationCountsEachLineInFull(): void
    {
        (new CategoryAggregator($this->resource))->categorySales($this->period(), null, 'full', null, 10);

        $this->assertSame('SUM(i.base_row_total * 1)', $this->columns['revenue']);
    }

    /**
     * Root categories hold every product; counting them would take a share of
     * every sale. Both the membership and the divisor leave them out.
     *
     * @return void
     */
    public function testRootCategoriesAreLeftOutOfMembershipAndDivisor(): void
    {
        (new CategoryAggregator($this->resource))->categorySales($this->period(), null, 'split', null, 10);

        $this->assertContains('ce.level >= ? => 2', $this->conditions);
        $this->assertContains('ce2.level >= ? => 2', $this->conditions);
    }

    /**
     * @return void
     */
    public function testASubtreeRestrictsMembershipAndDivisorToItsDescendants(): void
    {
        (new CategoryAggregator($this->resource))->categorySales($this->period(), null, 'split', 7, 10);

        $this->assertContains('ce.path LIKE ? => 1/2/7/%', $this->conditions);
        $this->assertContains('ce2.path LIKE ? => 1/2/7/%', $this->conditions);
    }

    /**
     * @return void
     */
    public function testAnUnknownParentCategoryIsRefused(): void
    {
        $this->fetchOne = false;
        $this->expectException(LocalizedException::class);

        (new CategoryAggregator($this->resource))->categorySales($this->period(), null, 'split', 99, 10);
    }

    /**
     * History is read to the end of the period and no further, so a report on
     * last year does not change when this year's orders arrive.
     *
     * @return void
     */
    public function testBuyerHistoryIsBoundedByThePeriodEnd(): void
    {
        (new BuyerAggregator($this->resource))->segments($this->period(), 3, '2025-10-02 21:59:59');

        $this->assertContains('o.created_at <= ? => 2026-03-31 21:59:59', $this->conditions);
        $this->assertNotContains('o.created_at >= ? => 2026-02-28 23:00:00', $this->conditions);
        $this->assertContains('o.store_id = ? => 3', $this->conditions);
        $this->assertSame('LOWER(o.customer_email)', $this->columns['buyer']);
    }

    /**
     * @return void
     */
    public function testNewAndReturningAreSplitOnTheFirstOrderAgainstThePeriodStart(): void
    {
        (new BuyerAggregator($this->resource))->segments($this->period(), null, '2025-10-02 21:59:59');

        $this->assertStringContainsString("b.first_order_at >= '2026-02-28 23:00:00'", $this->columns['new_buyers']);
        $this->assertStringContainsString(
            "b.first_order_at < '2026-02-28 23:00:00'",
            $this->columns['returning_buyers']
        );
        $this->assertStringContainsString("b.last_order_at < '2025-10-02 21:59:59'", $this->columns['inactive_buyers']);
    }

    /**
     * @return void
     */
    public function testAbandonedCartsAreOpenWithItemsAndIdleWithinTheWindow(): void
    {
        (new CartAggregator($this->resource))->summary('2026-03-14 12:00:00', '2026-02-13 12:00:00', null, true);

        $this->assertContains('q.is_active = ? => 1', $this->conditions);
        $this->assertContains('q.items_count > ? => 0', $this->conditions);
        $this->assertContains('q.updated_at <= ? => 2026-03-14 12:00:00', $this->conditions);
        $this->assertContains('q.updated_at >= ? => 2026-02-13 12:00:00', $this->conditions);
        $this->assertContains("q.customer_email IS NOT NULL AND q.customer_email <> ''", $this->conditions);
        $this->assertSame(['q.base_currency_code'], $this->groups);
    }

    /**
     * Stock is taken from the variant, so the rate is counted on the lines of
     * stocked types and never on the configurable parent.
     *
     * @return void
     */
    public function testInventoryRiskCountsStockedLinesAgainstTheLegacyStock(): void
    {
        (new StockAggregator($this->resource))->atRisk('2026-02-13 12:00:00', 30, 14.0, null, 50);

        $this->assertContains('i.product_type IN (?) => simple,virtual,downloadable', $this->conditions);
        $this->assertContains('o.state <> ? => canceled', $this->conditions);
        $this->assertContains("si.product_id = s.product_id AND si.stock_id = '1'", $this->joins);
        $this->assertContains('si.use_config_manage_stock = 1 OR si.manage_stock = 1', $this->conditions);
        $this->assertSame('(GREATEST(si.qty, 0) * 30 / s.units)', $this->columns['days_left']);
        $this->assertSame('SUM(i.qty_ordered - i.qty_canceled)', $this->columns['units']);
    }

    /**
     * @return Period
     */
    private function period(): Period
    {
        return new Period(
            'Europe/Berlin',
            '2026-03-01 00:00:00',
            '2026-03-31 23:59:59',
            '2026-02-28 23:00:00',
            '2026-03-31 21:59:59',
            'none',
            3600
        );
    }
}
