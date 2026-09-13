<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\InvoiceProjector;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What an invoice looks like to a model.
 *
 * Magento reports an invoice's state only as an integer, and that integer is
 * what decides whether capture_invoice and void_invoice will accept it — so the
 * label travels with it rather than leaving the agent to remember that 2 means
 * paid.
 *
 * @see InvoiceProjector
 */
class InvoiceProjectorTest extends TestCase
{
    private InvoiceProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->projector = new InvoiceProjector();
    }

    /**
     * @return void
     */
    public function testStateCarriesItsLabel(): void
    {
        $this->assertSame('open', $this->projector->toSummary($this->invoice(1))['state_label']);
        $this->assertSame('paid', $this->projector->toSummary($this->invoice(2))['state_label']);
        $this->assertSame('canceled', $this->projector->toSummary($this->invoice(3))['state_label']);
    }

    /**
     * @return void
     */
    public function testAnUnsetStateStaysNullRatherThanBecomingOpen(): void
    {
        $summary = $this->projector->toSummary($this->invoice(null));

        $this->assertNull($summary['state']);
        $this->assertNull($summary['state_label']);
    }

    /**
     * Magento hands monetary columns back as strings, and a total of zero must
     * not arrive as the string "0.0000" where a model is comparing numbers.
     *
     * @return void
     */
    public function testMoneyIsNumericAndNullSurvives(): void
    {
        $summary = $this->projector->toSummary($this->invoice(2));

        $this->assertSame(15.5, $summary['grand_total']);
        $this->assertNull($summary['total_refunded']);
    }

    /**
     * @return void
     */
    public function testDetailAddsLinesTotalsAndComments(): void
    {
        $item = $this->createMock(InvoiceItemInterface::class);
        $item->method('getOrderItemId')->willReturn(7);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getName')->willReturn('A thing');
        $item->method('getQty')->willReturn('2.0000');
        $item->method('getPrice')->willReturn('7.7500');
        $item->method('getRowTotal')->willReturn('15.5000');

        $invoice = $this->invoice(2);
        $invoice->method('getItems')->willReturn([$item]);
        $invoice->method('getComments')->willReturn(null);
        $invoice->method('getSubtotal')->willReturn('15.5000');

        $detail = $this->projector->toDetail($invoice);

        $this->assertSame('paid', $detail['state_label']);
        $this->assertSame(15.5, $detail['totals']['subtotal']);
        $this->assertSame([
            'order_item_id' => 7,
            'sku' => 'SKU-1',
            'name' => 'A thing',
            'qty' => 2.0,
            'price' => 7.75,
            'row_total' => 15.5,
        ], $detail['items'][0]);
        $this->assertSame([], $detail['comments']);
    }

    /**
     * @param int|null $state
     * @return InvoiceInterface&MockObject
     */
    private function invoice(?int $state): InvoiceInterface&MockObject
    {
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getEntityId')->willReturn(4);
        $invoice->method('getIncrementId')->willReturn('100000009');
        $invoice->method('getOrderId')->willReturn(3);
        $invoice->method('getStoreId')->willReturn(1);
        $invoice->method('getState')->willReturn($state);
        $invoice->method('getGrandTotal')->willReturn('15.5000');
        $invoice->method('getBaseTotalRefunded')->willReturn(null);

        return $invoice;
    }
}
