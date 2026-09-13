<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\CaptureInvoice;
use Magenx\AiMcp\Model\Tool\Sales\InvoiceLocator;
use Magenx\AiMcp\Model\Tool\Sales\InvoiceProjector;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceManagementInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Capturing payment for an open invoice.
 *
 * This one moves real money and cannot be reversed, so the invoice's state is
 * checked before the gateway is asked. Magento's own failure for an invoice
 * that is already paid arrives as a generic save error after the attempt, which
 * reads like a payment problem rather than the wrong invoice.
 *
 * @see CaptureInvoice
 */
class CaptureInvoiceTest extends TestCase
{
    private InvoiceLocator&MockObject $locator;
    private InvoiceManagementInterface&MockObject $invoiceManagement;
    private CaptureInvoice $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(InvoiceLocator::class);
        $this->invoiceManagement = $this->createMock(InvoiceManagementInterface::class);
        $this->tool = new CaptureInvoice(
            $this->locator,
            $this->invoiceManagement,
            new InvoiceProjector()
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheCaptureGrant(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Sales::capture', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAPaidInvoiceIsRefusedBeforeTheGatewayIsAsked(): void
    {
        $this->locator->method('locate')->willReturn($this->invoice(InvoiceProjector::STATE_PAID));
        $this->invoiceManagement->expects($this->never())->method('setCapture');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Invoice 100000009 is not open — it is already paid, so there is nothing to capture.'
        );
        $this->tool->execute(['entity_id' => 4]);
    }

    /**
     * @return void
     */
    public function testACanceledInvoiceIsRefused(): void
    {
        $this->locator->method('locate')->willReturn($this->invoice(InvoiceProjector::STATE_CANCELED));
        $this->invoiceManagement->expects($this->never())->method('setCapture');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('it is canceled');
        $this->tool->execute(['entity_id' => 4]);
    }

    /**
     * @return void
     */
    public function testAnOpenInvoiceIsCapturedAndReportedBack(): void
    {
        $this->locator->method('locate')->willReturn($this->invoice(InvoiceProjector::STATE_OPEN));
        $this->invoiceManagement->expects($this->once())->method('setCapture')->with(4);

        $result = $this->tool->execute(['entity_id' => 4]);

        $this->assertTrue($result['captured']);
        $this->assertSame(4, $result['invoice']['entity_id']);
    }

    /**
     * @param int $state
     * @return InvoiceInterface&MockObject
     */
    private function invoice(int $state): InvoiceInterface&MockObject
    {
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getEntityId')->willReturn(4);
        $invoice->method('getIncrementId')->willReturn('100000009');
        $invoice->method('getState')->willReturn($state);

        return $invoice;
    }
}
