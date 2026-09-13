<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\InvoiceInterface;

/**
 * Read one invoice in full.
 */
class GetInvoice extends AbstractTool
{
    /**
     * @param InvoiceLocator $locator
     * @param InvoiceProjector $projector
     */
    public function __construct(
        private readonly InvoiceLocator $locator,
        private readonly InvoiceProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_invoice';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one invoice by entity_id or its own increment_id: lines, totals and comments. '
            . 'state_label says whether it is open (payment not captured), paid or canceled — which '
            . 'is what decides whether capture_invoice and void_invoice will accept it.';
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
        return 'Magento_Sales::sales_invoice';
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

        return $this->projector->toDetail($invoice);
    }
}
