<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\OrderProjector;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The per-line quantities the sales write tools are driven by.
 *
 * Magento stores five counters and leaves the arithmetic to the caller. These
 * are the derived figures an agent reads before invoicing, shipping or
 * refunding, so getting one wrong is how a refund ends up larger than what was
 * paid.
 *
 * @see OrderProjector
 */
class OrderProjectorTest extends TestCase
{
    private OrderProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->projector = new OrderProjector();
    }

    /**
     * @return void
     */
    public function testUntouchedLineIsFullyInvoiceableAndShippableButNotRefundable(): void
    {
        $line = $this->projectSingleItem(['ordered' => 4.0]);

        $this->assertSame(4.0, $line['qty_invoiceable']);
        $this->assertSame(4.0, $line['qty_shippable']);
        // Nothing has been invoiced, so there is nothing to refund yet.
        $this->assertSame(0.0, $line['qty_refundable']);
    }

    /**
     * @return void
     */
    public function testPartlyProcessedLineReportsWhatRemains(): void
    {
        $line = $this->projectSingleItem([
            'ordered' => 4.0,
            'invoiced' => 3.0,
            'shipped' => 2.0,
            'refunded' => 1.0,
            'canceled' => 1.0,
        ]);

        $this->assertSame(0.0, $line['qty_invoiceable'], 'ordered - invoiced - canceled');
        // Magento permits shipping before invoicing, so this is not bounded by
        // the invoiced quantity.
        $this->assertSame(1.0, $line['qty_shippable'], 'ordered - shipped - canceled');
        $this->assertSame(2.0, $line['qty_refundable'], 'invoiced - refunded');
    }

    /**
     * Data that has drifted — a refund recorded outside Magento, a migrated
     * order — must not hand a write tool a negative allowance to act on.
     *
     * @return void
     */
    public function testQuantitiesNeverGoNegative(): void
    {
        $line = $this->projectSingleItem(['ordered' => 1.0, 'invoiced' => 1.0, 'refunded' => 2.0]);

        $this->assertSame(0.0, $line['qty_refundable']);
        $this->assertSame(0.0, $line['qty_invoiceable']);
    }

    /**
     * A configurable order carries the parent line and its simple child. The
     * child holds no quantity of its own to act on, and listing it invites a
     * write against the wrong order_item_id.
     *
     * @return void
     */
    public function testChildLinesAreExcluded(): void
    {
        $parent = $this->orderItem(['id' => 10, 'ordered' => 1.0]);
        $child = $this->orderItem(['id' => 11, 'ordered' => 1.0, 'parent' => 10]);

        $detail = $this->projector->toDetail($this->order([$parent, $child]));

        $this->assertCount(1, $detail['items']);
        $this->assertSame(10, $detail['items'][0]['order_item_id']);
    }

    /**
     * Magento returns monetary columns as strings like "40.0000". A model reads
     * a number more reliably, and an absent total must stay absent rather than
     * being reported as zero paid.
     *
     * @return void
     */
    public function testMonetaryColumnsBecomeNumbersAndNullSurvives(): void
    {
        $order = $this->order([$this->orderItem(['ordered' => 1.0])]);
        $order->method('getGrandTotal')->willReturn('40.0000');
        $order->method('getTotalPaid')->willReturn(null);

        $summary = $this->projector->toSummary($order);

        $this->assertSame(40.0, $summary['grand_total']);
        $this->assertNull($summary['total_paid']);
    }

    /**
     * @return void
     */
    public function testSummaryIdentifiesTheCustomer(): void
    {
        $order = $this->order([]);
        $order->method('getCustomerFirstname')->willReturn('Ada');
        $order->method('getCustomerLastname')->willReturn('Lovelace');
        $order->method('getCustomerIsGuest')->willReturn(1);

        $summary = $this->projector->toSummary($order);

        $this->assertSame('Ada Lovelace', $summary['customer_name']);
        $this->assertTrue($summary['is_guest']);
    }

    /**
     * Project one line and return it.
     *
     * @param array<string, mixed> $quantities
     * @return array<string, mixed>
     */
    private function projectSingleItem(array $quantities): array
    {
        $detail = $this->projector->toDetail($this->order([$this->orderItem($quantities)]));

        return $detail['items'][0];
    }

    /**
     * @param array<string, mixed> $quantities
     * @return OrderItemInterface&MockObject
     */
    private function orderItem(array $quantities): OrderItemInterface&MockObject
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($quantities['id'] ?? 1);
        $item->method('getParentItemId')->willReturn($quantities['parent'] ?? null);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getName')->willReturn('Item');
        $item->method('getPrice')->willReturn('10.0000');
        $item->method('getRowTotal')->willReturn('20.0000');
        $item->method('getQtyOrdered')->willReturn($quantities['ordered'] ?? 0.0);
        $item->method('getQtyInvoiced')->willReturn($quantities['invoiced'] ?? 0.0);
        $item->method('getQtyShipped')->willReturn($quantities['shipped'] ?? 0.0);
        $item->method('getQtyRefunded')->willReturn($quantities['refunded'] ?? 0.0);
        $item->method('getQtyCanceled')->willReturn($quantities['canceled'] ?? 0.0);

        return $item;
    }

    /**
     * @param OrderItemInterface[] $items
     * @return OrderInterface&MockObject
     */
    private function order(array $items): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(5);
        $order->method('getIncrementId')->willReturn('000000005');
        $order->method('getItems')->willReturn($items);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getPayment')->willReturn(null);
        $order->method('getExtensionAttributes')->willReturn(null);

        return $order;
    }
}
