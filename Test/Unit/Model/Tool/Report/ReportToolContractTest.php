<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Report\CustomerSummary;
use Magenx\AiMcp\Model\Tool\Report\OrderStatusBreakdown;
use Magenx\AiMcp\Model\Tool\Report\PeriodResolver;
use Magenx\AiMcp\Model\Tool\Report\ReportArguments;
use Magenx\AiMcp\Model\Tool\Report\SalesAggregator;
use Magenx\AiMcp\Model\Tool\Report\SalesByPeriod;
use Magenx\AiMcp\Model\Tool\Report\SalesSummary;
use Magenx\AiMcp\Model\Tool\Report\TopProducts;
use Magenx\AiMcp\Model\Tool\Report\TotalsProjector;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * What the five report tools promise before they touch a database.
 *
 * Revenue sits behind its own grant rather than the one every order-reading
 * tool uses, and that split is only real if each tool actually returns it — a
 * single tool falling back to Magento_Sales::actions_view would quietly hand
 * the figures to any integration that can read an order.
 *
 * @see ReportArguments::ACL_RESOURCE
 */
class ReportToolContractTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function toolProvider(): array
    {
        return [
            'sales_summary' => [SalesSummary::class],
            'sales_by_period' => [SalesByPeriod::class],
            'top_products' => [TopProducts::class],
            'order_status_breakdown' => [OrderStatusBreakdown::class],
            'customer_summary' => [CustomerSummary::class],
        ];
    }

    /**
     * @param string $class
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toolProvider')]
    public function testEveryReportIsAReadBehindTheReportsGrant(string $class): void
    {
        $tool = $this->tool($class);

        $this->assertFalse($tool->isWrite());
        $this->assertSame('Magenx_AiMcp::reports', $tool->getAclResource());
    }

    /**
     * @param string $class
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toolProvider')]
    public function testEveryReportAdvertisesAClosedSchemaWithTheSharedPeriodArguments(string $class): void
    {
        $schema = $this->tool($class)->getInputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        foreach (['period', 'date_from', 'date_to', 'store_code'] as $argument) {
            $this->assertArrayHasKey($argument, $schema['properties']);
        }
        $this->assertArrayNotHasKey('confirm', $schema['properties'], 'The server injects confirm.');
    }

    /**
     * Only the bucketed report takes a granularity; offering it on the others
     * would advertise an argument they ignore.
     *
     * @return void
     */
    public function testGranularityIsOfferedOnlyWhereItDoesSomething(): void
    {
        $this->assertArrayHasKey(
            'granularity',
            $this->tool(SalesByPeriod::class)->getInputSchema()['properties']
        );
        $this->assertArrayNotHasKey(
            'granularity',
            $this->tool(SalesSummary::class)->getInputSchema()['properties']
        );
    }

    /**
     * The admin scope carries no orders, so filtering on it would return an
     * empty report — which reads as "no sales" rather than "wrong question".
     *
     * @return void
     */
    public function testTheAdminScopeIsRefusedRatherThanReportingNothing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('admin scope has no orders');

        $this->tool(SalesSummary::class)->execute(['store_code' => 'admin']);
    }

    /**
     * @param string $class
     * @return ToolInterface
     */
    private function tool(string $class): ToolInterface
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');

        $clock = $this->createMock(CoreDate::class);
        $clock->method('gmtTimestamp')->willReturn(1773576000);

        $stores = $this->createMock(StoreResolver::class);
        $stores->method('resolve')->willReturn(0);

        $arguments = new ReportArguments(new PeriodResolver($timezone, $clock), $stores);
        $aggregator = $this->createMock(SalesAggregator::class);

        return match ($class) {
            SalesSummary::class => new SalesSummary($arguments, $aggregator, new TotalsProjector()),
            SalesByPeriod::class => new SalesByPeriod($arguments, $aggregator, new TotalsProjector()),
            TopProducts::class => new TopProducts($arguments, $aggregator),
            OrderStatusBreakdown::class => new OrderStatusBreakdown($arguments, $aggregator),
            default => new CustomerSummary($arguments, $aggregator),
        };
    }
}
