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
 * Void an open invoice, releasing the authorization behind it.
 */
class VoidInvoice extends AbstractTool
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
        return 'void_invoice';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Void an open invoice, telling the gateway to release the authorization instead of '
            . 'capturing it. The invoice document itself stays on the order, canceled — Magento '
            . 'never deletes one — and this cannot be undone: billing that order again means a new '
            . 'invoice. Only works while the invoice is open and only for a payment method that '
            . 'supports voiding; a captured invoice is refunded with create_credit_memo instead.';
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
            throw new LocalizedException(__(
                'Invoice %1 is not open — it is %2. A paid invoice is refunded with '
                    . 'create_credit_memo, not voided.',
                $invoice->getIncrementId(),
                $state === InvoiceProjector::STATE_PAID ? 'already paid' : 'already canceled'
            ));
        }

        $this->invoiceManagement->setVoid((int) $invoice->getEntityId());

        return [
            'voided' => true,
            'invoice' => $this->projector->toSummary(
                $this->locator->locate(null, (int) $invoice->getEntityId())
            ),
        ];
    }
}
