<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;

/**
 * Put a product in a cart.
 *
 * **The price is the store's, never the caller's.** `CartItemInterface` has a
 * settable `price` and this tool does not expose it. An agent naming its own
 * price is a discount with no rule behind it, no record of who authorised it
 * and nothing in the catalogue to reconcile against — and it would be invisible
 * on the order, which shows a price without saying where it came from. Discounts
 * belong to cart price rules, which this server can already read and write.
 *
 * Stock is left to Magento. An out-of-stock product, or one with less on hand
 * than asked for, fails inside the repository with a message that names the sku
 * and the shortfall; that message is better than anything a pre-check here
 * would produce, and a pre-check would race the save anyway.
 */
class AddCartItem extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartItemInterfaceFactory $cartItemFactory
     * @param CartItemRepositoryInterface $cartItemRepository
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartItemInterfaceFactory $cartItemFactory,
        private readonly CartItemRepositoryInterface $cartItemRepository,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_cart_item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a product to a cart by sku. The price is always the store\'s current price for '
            . 'that store view — a price cannot be set here, because a discount with no rule '
            . 'behind it leaves nothing to reconcile the order against; use a cart price rule '
            . 'instead. Adding a sku already in the cart increases its quantity rather than '
            . 'adding a second line. Out-of-stock and insufficient quantity are refused by '
            . 'Magento with the shortfall named.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'sku' => ['type' => 'string', 'description' => 'Product sku to add.'],
                    'qty' => [
                        'type' => 'number',
                        'description' => 'How many. Fractional quantities are allowed for products '
                            . 'configured to take them.',
                    ],
                ]
            ),
            'required' => ['cart_id', 'sku', 'qty'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cart::manage';
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
    protected function isDestructive(): bool
    {
        // Adds a line, or adds to one. Nothing is removed and nothing is
        // charged — the cart is not an order until place_order.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $cartId = $this->requireInt($arguments, 'cart_id');
        $this->locator->locateActive($cartId);

        // Read and check every argument before building anything, so a bad
        // quantity is a message rather than a half-made item.
        $sku = $this->requireString($arguments, 'sku');
        $qty = $this->quantity($arguments);

        $item = $this->cartItemFactory->create();
        $item->setQuoteId($cartId);
        $item->setSku($sku);
        $item->setQty($qty);

        $saved = $this->cartItemRepository->save($item);

        return [
            'added' => true,
            'tool' => $this->getName(),
            'item_id' => $saved->getItemId() === null ? null : (int) $saved->getItemId(),
            'sku' => $saved->getSku(),
            'qty' => $saved->getQty() === null ? null : (float) $saved->getQty(),
            'price' => $saved->getPrice() === null ? null : (float) $saved->getPrice(),
        ] + $this->projector->toDetail($this->locator->locate($cartId));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return float
     * @throws LocalizedException
     */
    private function quantity(array $arguments): float
    {
        $qty = $arguments['qty'] ?? null;
        if (!is_int($qty) && !is_float($qty) && !(is_string($qty) && is_numeric($qty))) {
            throw new LocalizedException(__('The "qty" argument must be a number.'));
        }

        if ((float) $qty <= 0) {
            // Zero would save without error and add a line of nothing; a
            // negative would be read by Magento as a quantity it cannot fill.
            throw new LocalizedException(__(
                'The "qty" argument must be greater than zero. To take a product out of the cart, '
                . 'use remove_cart_item.'
            ));
        }

        return (float) $qty;
    }
}
