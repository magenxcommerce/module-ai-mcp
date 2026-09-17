<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one cart in full: its items and what it comes to.
 */
class GetCart extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_cart';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one cart: every line, the subtotal, discount, shipping, tax and grand total, '
            . 'and whether it is still open. A cart that has been placed as an order is kept '
            . 'rather than deleted, so this also reads back what a completed cart contained — '
            . 'reserved_order_id names the order it became.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'required' => ['cart_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cart::cart';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        // Deliberately locate() rather than locateActive(): reading a placed
        // cart is exactly how you find out what was in it.
        return $this->projector->toDetail(
            $this->locator->locate($this->requireInt($arguments, 'cart_id'))
        );
    }
}
