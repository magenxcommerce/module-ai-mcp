<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Report\AbandonedCarts;
use Magenx\AiMcp\Model\Tool\Report\BuyerAggregator;
use Magenx\AiMcp\Model\Tool\Report\BuyerSegments;
use Magenx\AiMcp\Model\Tool\Report\CartAggregator;
use Magenx\AiMcp\Model\Tool\Report\CategoryAggregator;
use Magenx\AiMcp\Model\Tool\Report\CategoryNames;
use Magenx\AiMcp\Model\Tool\Report\CategorySales;
use Magenx\AiMcp\Model\Tool\Report\CouponPerformance;
use Magenx\AiMcp\Model\Tool\Report\InventoryRisk;
use Magenx\AiMcp\Model\Tool\Report\PeriodResolver;
use Magenx\AiMcp\Model\Tool\Report\ReportArguments;
use Magenx\AiMcp\Model\Tool\Report\SalesAggregator;
use Magenx\AiMcp\Model\Tool\Report\SalesByCountry;
use Magenx\AiMcp\Model\Tool\Report\StockAggregator;
use Magenx\AiMcp\Model\Tool\Report\TotalsProjector;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * The newer report tools: their contract, and the figures they derive.
 *
 * The clock is fixed at 2026-03-15 12:00:00 UTC.
 */
class InsightToolsTest extends TestCase
{
    private const NOW = 1773576000;

    /**
     * @return array<string, array{0: string}>
     */
    public static function toolProvider(): array
    {
        return [
            'sales_by_country' => [SalesByCountry::class],
            'category_sales' => [CategorySales::class],
            'coupon_performance' => [CouponPerformance::class],
            'buyer_segments' => [BuyerSegments::class],
            'abandoned_carts' => [AbandonedCarts::class],
            'inventory_risk' => [InventoryRisk::class],
        ];
    }

    /**
     * @param string $class
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toolProvider')]
    public function testEveryNewReportIsAClosedReadBehindTheReportsGrant(string $class): void
    {
        $tool = $this->tool($class);
        $schema = $tool->getInputSchema();

        $this->assertFalse($tool->isWrite());
        $this->assertSame('Magenx_AiMcp::reports', $tool->getAclResource());
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('store_code', $schema['properties']);
    }

    /**
     * Only the cart list names a person.
     *
     * @return void
     */
    public function testOnlyAbandonedCartsIsPersonalData(): void
    {
        foreach (array_column(self::toolProvider(), 0) as $class) {
            $this->assertSame(
                $class === AbandonedCarts::class,
                $this->tool($class) instanceof PersonalDataToolInterface,
                $class
            );
        }
    }

    /**
     * @return void
     */
    public function testCountriesCarryTheSummaryFigures(): void
    {
        $sales = $this->createMock(SalesAggregator::class);
        $sales->expects($this->once())->method('totalsByCountry')
            ->with($this->anything(), null, 'shipping', 250)
            ->willReturn([['country_id' => 'DE', 'currency' => 'EUR', 'orders' => '4', 'ordered_total' => '400']]);

        $result = $this->tool(SalesByCountry::class, sales: $sales)
            ->execute(['address_type' => 'shipping', 'limit' => 9999]);

        $this->assertSame('DE', $result['items'][0]['country_id']);
        $this->assertSame(100.0, $result['items'][0]['average_order_value']);
    }

    /**
     * @return void
     */
    public function testAnUnknownAddressTypeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tool(SalesByCountry::class)->execute(['address_type' => 'delivery']);
    }

    /**
     * @return void
     */
    public function testTheCouponBaselineComparesAverageOrderValues(): void
    {
        $sales = $this->createMock(SalesAggregator::class);
        $sales->method('couponBaseline')->willReturn([[
            'currency' => 'EUR',
            'orders_with_coupon' => '10',
            'ordered_with_coupon' => '500',
            'orders_without_coupon' => '30',
            'ordered_without_coupon' => '2100',
        ]]);
        $sales->method('couponPerformance')->willReturn([[
            'coupon_code' => 'SPRING',
            'rule_id' => '3',
            'rule_name' => 'Spring',
            'currency' => 'EUR',
            'orders' => '4',
            'ordered_total' => '200',
            'discount_total' => '-40',
        ]]);

        $result = $this->tool(CouponPerformance::class, sales: $sales)->execute([]);

        $this->assertSame(25.0, $result['baseline'][0]['coupon_order_share_percent']);
        $this->assertSame(50.0, $result['baseline'][0]['average_order_value_with_coupon']);
        $this->assertSame(70.0, $result['baseline'][0]['average_order_value_without_coupon']);
        $this->assertSame(3, $result['items'][0]['rule_id']);
        $this->assertSame(-40.0, $result['items'][0]['discount_total']);
    }

    /**
     * @return void
     */
    public function testCategorySalesNamesTheCategoriesAndSaysWhetherTheyAddUp(): void
    {
        $categories = $this->createMock(CategoryAggregator::class);
        $categories->expects($this->once())->method('categorySales')
            ->with($this->anything(), null, 'full', 4, 25)
            ->willReturn([[
                'category_id' => '12',
                'path' => '1/2/4/12',
                'currency' => 'EUR',
                'orders' => '3',
                'units' => '5.0000',
                'revenue' => '150.0000',
                'discount' => '0',
            ]]);
        $names = $this->createMock(CategoryNames::class);
        $names->method('forIds')->with([12])->willReturn([12 => 'Shoes']);

        $result = $this->tool(CategorySales::class, categories: $categories, names: $names)
            ->execute(['allocation' => 'full', 'parent_category_id' => 4]);

        $this->assertTrue($result['sums_exceed_total']);
        $this->assertSame('Shoes', $result['items'][0]['name']);
        $this->assertSame(50.0, $result['items'][0]['average_order_value']);
    }

    /**
     * @return void
     */
    public function testBuyerSegmentsDeriveTheShareAndTheInactiveCutoff(): void
    {
        $buyers = $this->createMock(BuyerAggregator::class);
        $buyers->expects($this->once())->method('segments')
            ->with($this->anything(), null, '2025-12-31 23:59:59')
            ->willReturn([[
                'currency' => 'EUR',
                'known_buyers' => '100',
                'new_buyers' => '6',
                'returning_buyers' => '2',
                'inactive_buyers' => '40',
            ]]);

        $result = $this->tool(BuyerSegments::class, buyers: $buyers)
            ->execute(['period' => 'last_month', 'inactive_days' => 59]);

        $this->assertSame(8, $result['segments'][0]['buyers_in_period']);
        $this->assertSame(25.0, $result['segments'][0]['returning_share_percent']);
        $this->assertSame(40, $result['segments'][0]['inactive_buyers']);
    }

    /**
     * @return void
     */
    public function testAbandonedCartsMeasureIdlenessFromNow(): void
    {
        $carts = $this->createMock(CartAggregator::class);
        $carts->expects($this->once())->method('summary')
            ->with('2026-03-14 12:00:00', '2026-03-08 12:00:00', null, true)
            ->willReturn([]);
        $carts->method('carts')->willReturn([]);

        $result = $this->tool(AbandonedCarts::class, carts: $carts)
            ->execute(['inactivity_hours' => 24, 'max_age_days' => 7, 'has_email' => true]);

        $this->assertSame('2026-03-14 12:00:00', $result['idle_since_utc']);
    }

    /**
     * @return void
     */
    public function testInventoryRiskReportsTheDailyRate(): void
    {
        $stock = $this->createMock(StockAggregator::class);
        $stock->expects($this->once())->method('atRisk')
            ->with('2026-03-08 12:00:00', 7, 14.0, null, 50)
            ->willReturn([[
                'product_id' => '5',
                'sku' => 'MUG',
                'qty' => '3',
                'is_in_stock' => '1',
                'units_sold' => '14',
                'days_left' => '1.5',
            ]]);

        $result = $this->tool(InventoryRisk::class, stock: $stock)->execute(['velocity_days' => 7]);

        $this->assertSame(2.0, $result['items'][0]['units_per_day']);
        $this->assertSame(1.5, $result['items'][0]['days_left']);
        $this->assertTrue($result['items'][0]['is_in_stock']);
    }

    /**
     * @param string $class
     * @param SalesAggregator|null $sales
     * @param CategoryAggregator|null $categories
     * @param CategoryNames|null $names
     * @param BuyerAggregator|null $buyers
     * @param CartAggregator|null $carts
     * @param StockAggregator|null $stock
     * @return ToolInterface
     */
    private function tool(
        string $class,
        ?SalesAggregator $sales = null,
        ?CategoryAggregator $categories = null,
        ?CategoryNames $names = null,
        ?BuyerAggregator $buyers = null,
        ?CartAggregator $carts = null,
        ?StockAggregator $stock = null
    ): ToolInterface {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $clock = $this->createMock(CoreDate::class);
        $clock->method('gmtTimestamp')->willReturn(self::NOW);
        $arguments = new ReportArguments(
            new PeriodResolver($timezone, $clock),
            $this->createMock(StoreResolver::class)
        );

        return match ($class) {
            SalesByCountry::class => new SalesByCountry(
                $arguments,
                $sales ?? $this->createMock(SalesAggregator::class),
                new TotalsProjector()
            ),
            CouponPerformance::class => new CouponPerformance(
                $arguments,
                $sales ?? $this->createMock(SalesAggregator::class)
            ),
            CategorySales::class => new CategorySales(
                $arguments,
                $categories ?? $this->createMock(CategoryAggregator::class),
                $names ?? $this->createMock(CategoryNames::class)
            ),
            BuyerSegments::class => new BuyerSegments(
                $arguments,
                $buyers ?? $this->createMock(BuyerAggregator::class)
            ),
            AbandonedCarts::class => new AbandonedCarts(
                $arguments,
                $carts ?? $this->createMock(CartAggregator::class),
                $clock
            ),
            default => new InventoryRisk(
                $arguments,
                $stock ?? $this->createMock(StockAggregator::class),
                $clock
            ),
        };
    }
}
