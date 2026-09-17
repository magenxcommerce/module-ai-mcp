<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;

/**
 * Change how many of a line a cart holds.
 *
 * Quantity only, and absolute rather than a delta — the same choice update_stock
 * makes, for the same reason: a delta applied twice is a different cart, and a
 * retry after a timeout cannot tell whether the first call landed.
 *
 * The item has to be looked up before it is written. Magento's item save takes
 * a sku and a quote id, so a wrong item_id would not fail — it would add a
 * second line, and the caller would see a success for an edit that quietly
 * became an addition.
 */
class UpdateCartItem extends AbstractTool
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
        return 'update_cart_item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change the quantity of a line already in a cart. The quantity is the new total '
            . 'for that line, not an amount to add. Prices cannot be changed here. To take the '
            . 'line out entirely use remove_cart_item — a quantity of zero is refused, because '
            . 'Magento treats it inconsistently.';
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
                    'item_id' => [
                        'type' => 'integer',
                        'description' => 'Line to change, as get_cart reports it.',
                    ],
                    'qty' => [
                        'type' => 'number',
                        'description' => 'The new quantity for the line, replacing the current one.',
                    ],
                ]
            ),
            'required' => ['cart_id', 'item_id', 'qty'],
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
        // Sets one line's quantity. Nothing is removed — that is
        // remove_cart_item — and nothing is charged.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
        // The quantity given becomes the line's quantity, so a repeat lands on
        // the same number.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $cartId = $this->requireInt($arguments, 'cart_id');
        $itemId = $this->requireInt($arguments, 'item_id');
        $cart = $this->locator->locateActive($cartId);

        $qty = $this->quantity($arguments);
        $existing = $this->findItem($cart->getItems() ?? [], $itemId, $cartId);

        $item = $this->cartItemFactory->create();
        $item->setQuoteId($cartId);
        $item->setItemId($itemId);
        // The sku is what Magento matches on; without it the save adds a line
        // rather than changing one.
        $item->setSku($existing->getSku());
        $item->setQty($qty);

        $saved = $this->cartItemRepository->save($item);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'item_id' => $itemId,
            'sku' => $saved->getSku(),
            'qty' => $saved->getQty() === null ? null : (float) $saved->getQty(),
        ] + $this->projector->toDetail($this->locator->locate($cartId));
    }

    /**
     * @param array<int, CartItemInterface> $items
     * @param int $itemId
     * @param int $cartId
     * @return CartItemInterface
     * @throws LocalizedException
     */
    private function findItem(array $items, int $itemId, int $cartId): CartItemInterface
    {
        foreach ($items as $item) {
            if ((int) $item->getItemId() === $itemId) {
                return $item;
            }
        }

        throw new LocalizedException(__(
            'Cart %1 has no line with item_id %2. get_cart lists the lines with their ids.',
            $cartId,
            $itemId
        ));
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
            throw new LocalizedException(__(
                'The "qty" argument must be greater than zero. Use remove_cart_item to take the '
                . 'line out of the cart.'
            ));
        }

        return (float) $qty;
    }
}
