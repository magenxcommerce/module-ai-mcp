<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Finds a cart, and says whether it is still one.
 *
 * Every cart tool but the reads has to refuse a cart that has already been
 * placed, so the check lives here rather than in eleven `execute()` methods.
 *
 * The distinction it draws is not obvious from the outside. Placing an order
 * does not delete the quote — it deactivates it and records what it became. So
 * a placed cart still loads, still lists its items, and still answers every
 * getter; it simply ignores everything written to it from then on. A tool that
 * did not check would run an agent through add-item, set-delivery and
 * set-payment, report success at each step, and change nothing at all.
 */
class CartLocator
{
    /**
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(private readonly CartRepositoryInterface $cartRepository)
    {
    }

    /**
     * Load a cart, whatever state it is in.
     *
     * @param int $cartId
     * @return CartInterface
     * @throws LocalizedException
     */
    public function locate(int $cartId): CartInterface
    {
        try {
            return $this->cartRepository->get($cartId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No cart exists with cart_id %1. Use search_carts to find one, or create_cart to '
                . 'start a new one.',
                $cartId
            ));
        }
    }

    /**
     * Load a cart that can still be changed.
     *
     * @param int $cartId
     * @return CartInterface
     * @throws LocalizedException
     */
    public function locateActive(int $cartId): CartInterface
    {
        $cart = $this->locate($cartId);

        if ((bool) $cart->getIsActive()) {
            return $cart;
        }

        $orderId = $cart->getReservedOrderId();

        throw new LocalizedException(__(
            'Cart %1 has already been placed as an order%2, so it can no longer be changed — '
            . 'Magento keeps the cart as a record of what was bought rather than deleting it. '
            . 'Use search_orders to find the order, or create_cart to start a new one.',
            $cartId,
            $orderId === null || $orderId === '' ? '' : sprintf(' (%s)', $orderId)
        ));
    }

    /**
     * Schema fragment for the argument that names a cart.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'cart_id' => [
                'type' => 'integer',
                'description' => 'The cart id, as create_cart returned it or search_carts reports it.',
            ],
        ];
    }
}
