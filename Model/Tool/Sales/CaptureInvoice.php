<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceManagementInterface;

/**
 * Capture payment for an invoice that was created without capturing.
 *
 * create_invoice can capture at creation; an invoice created without it sits
 * open, with the money still only authorized. This is the second half.
 */
class CaptureInvoice extends AbstractTool
{
    /**
     * @param InvoiceLocator $locator
     * @param InvoiceManagementInterface $invoiceManagement
     * @param InvoiceProjector $projector
     */
    public function __construct(
        private readonly InvoiceLocator $locator,
        private readonly InvoiceManagementInterface $invoiceManagement,
        private readonly InvoiceProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'capture_invoice';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Capture payment for an open invoice through the payment gateway. Real money moves '
            . 'from the customer, and Magento has no operation that reverses a capture — refunding '
            . 'means create_credit_memo, which is a separate irreversible action. Only an invoice '
            . 'in state "open" can be captured; get_invoice reports state_label.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::capture';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        /** @var InvoiceInterface $invoice */
        $invoice = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'entity_id')
        );

        $state = $invoice->getState() === null ? null : (int) $invoice->getState();
        if ($state !== InvoiceProjector::STATE_OPEN) {
            // Magento's own failure here is a generic save error raised after
            // the gateway has already been asked, which reads like a payment
            // problem rather than the wrong invoice.
            throw new LocalizedException(__(
                'Invoice %1 is not open — it is %2, so there is nothing to capture.',
                $invoice->getIncrementId(),
                $state === InvoiceProjector::STATE_PAID ? 'already paid' : 'canceled'
            ));
        }

        $this->invoiceManagement->setCapture((int) $invoice->getEntityId());

        return [
            'captured' => true,
            'invoice' => $this->projector->toSummary(
                $this->locator->locate(null, (int) $invoice->getEntityId())
            ),
        ];
    }
}
