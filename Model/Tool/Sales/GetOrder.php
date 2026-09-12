<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one order in full, including what can still be done to it.
 */
class GetOrder extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param OrderProjector $projector
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly OrderProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one order by increment_id or order_id: items, addresses, payment, totals and '
            . 'status. Each item reports qty_invoiceable, qty_shippable and qty_refundable — the '
            . 'quantities create_invoice, create_shipment and create_credit_memo will accept. Call '
            . 'this before any of those three rather than assuming the ordered quantity is still '
            . 'available.';
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
        return 'Magento_Sales::actions_view';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        return $this->projector->toDetail($order);
    }
}
