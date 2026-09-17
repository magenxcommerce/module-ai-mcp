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

/**
 * Take a line out of a cart.
 *
 * The line is read before it is deleted so the result can name what went. A
 * confirm preview showing only an `item_id` tells nobody which product is about
 * to leave the cart, and item ids are not memorable.
 */
class RemoveCartItem extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartItemRepositoryInterface $cartItemRepository
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartItemRepositoryInterface $cartItemRepository,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'remove_cart_item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Take a line out of a cart entirely. The cart itself is untouched and can still be '
            . 'added to. Nothing has been charged at this point — removing a line from a cart is '
            . 'not a refund.';
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
                ['item_id' => ['type' => 'integer', 'description' => 'Line to remove, as get_cart reports it.']]
            ),
            'required' => ['cart_id', 'item_id'],
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
    public function execute(array $arguments): array
    {
        $cartId = $this->requireInt($arguments, 'cart_id');
        $itemId = $this->requireInt($arguments, 'item_id');
        $cart = $this->locator->locateActive($cartId);

        // Read it while it is still there; afterwards there is nothing to name.
        $removed = $this->findItem($cart->getItems() ?? [], $itemId, $cartId);
        $sku = $removed->getSku();
        $qty = $removed->getQty() === null ? null : (float) $removed->getQty();

        $this->cartItemRepository->deleteById($cartId, $itemId);

        return [
            'removed' => true,
            'tool' => $this->getName(),
            'item_id' => $itemId,
            'sku' => $sku,
            'qty' => $qty,
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
}
