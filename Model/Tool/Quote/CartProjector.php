<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * Presents a cart.
 *
 * The split between summary and detail is forced by the contract rather than
 * chosen. `CartInterface` carries no money at all — it has `items_count`,
 * `items_qty`, `is_active` and `is_virtual`, and nothing else about value — so
 * every figure a caller would call a total comes from a second service, one
 * call per cart. A page of fifty carts cannot afford fifty of those, and a
 * caller reading one cart almost always wants them. Hence: the summary is the
 * quote row, the detail adds the items and the totals.
 *
 * The other thing reported everywhere is whether the cart is still a cart.
 * Placing an order deactivates the quote rather than deleting it, so an
 * inactive row is not a broken cart — it is an order, and `reserved_order_id`
 * says which. A caller that reads an inactive cart as editable will make a
 * sequence of calls that all appear to succeed and change nothing.
 */
class CartProjector
{
    /**
     * @param CartTotalRepositoryInterface $cartTotalRepository
     */
    public function __construct(private readonly CartTotalRepositoryInterface $cartTotalRepository)
    {
    }

    /**
     * @param CartInterface $cart
     * @return array<string, mixed>
     */
    public function toSummary(CartInterface $cart): array
    {
        $customer = $cart->getCustomer();

        return [
            'cart_id' => (int) $cart->getId(),
            'store_id' => (int) $cart->getStoreId(),
            'is_active' => (bool) $cart->getIsActive(),
            'is_virtual' => (bool) $cart->getIsVirtual(),
            'items_count' => $this->intOrNull($cart->getItemsCount()),
            'items_qty' => $this->floatOrNull($cart->getItemsQty()),
            'currency' => $this->currencyCode($cart),
            'customer_id' => $customer === null ? null : $this->intOrNull($customer->getId()),
            'customer_email' => $customer === null ? null : $customer->getEmail(),
            'customer_is_guest' => (bool) $cart->getCustomerIsGuest(),
            // What this cart became, if it became anything. The pair of these
            // two is the difference between "abandoned" and "bought".
            'reserved_order_id' => $cart->getReservedOrderId(),
            'converted_at' => $cart->getConvertedAt(),
            'created_at' => $cart->getCreatedAt(),
            'updated_at' => $cart->getUpdatedAt(),
        ];
    }

    /**
     * @param CartInterface $cart
     * @return array<string, mixed>
     */
    public function toDetail(CartInterface $cart): array
    {
        $detail = $this->toSummary($cart);

        $detail['customer_note'] = $cart->getCustomerNote();
        $detail['items'] = $this->items($cart);
        $detail['totals'] = $this->totals((int) $cart->getId());

        return $detail;
    }

    /**
     * @param CartInterface $cart
     * @return array<int, array<string, mixed>>
     */
    public function items(CartInterface $cart): array
    {
        $items = [];

        foreach ($cart->getItems() ?? [] as $item) {
            /** @var CartItemInterface $item */
            $items[] = [
                'item_id' => $this->intOrNull($item->getItemId()),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => $this->floatOrNull($item->getQty()),
                'price' => $this->floatOrNull($item->getPrice()),
                'product_type' => $item->getProductType(),
            ];
        }

        return $items;
    }

    /**
     * The money, which the cart itself does not carry.
     *
     * Reported as null rather than as zeroes when it cannot be read: a cart
     * whose totals service refuses is not a cart worth nothing, and the two
     * must not look alike to a caller deciding whether to place the order.
     *
     * @param int $cartId
     * @return array<string, mixed>|null
     */
    private function totals(int $cartId): ?array
    {
        try {
            $totals = $this->cartTotalRepository->get($cartId);
        } catch (\Throwable) {
            return null;
        }

        return [
            'currency' => $totals->getQuoteCurrencyCode(),
            'base_currency' => $totals->getBaseCurrencyCode(),
            'subtotal' => $this->floatOrNull($totals->getSubtotal()),
            'subtotal_incl_tax' => $this->floatOrNull($totals->getSubtotalInclTax()),
            'discount_amount' => $this->floatOrNull($totals->getDiscountAmount()),
            'shipping_amount' => $this->floatOrNull($totals->getShippingAmount()),
            'shipping_incl_tax' => $this->floatOrNull($totals->getShippingInclTax()),
            'tax_amount' => $this->floatOrNull($totals->getTaxAmount()),
            'grand_total' => $this->floatOrNull($totals->getGrandTotal()),
            'base_grand_total' => $this->floatOrNull($totals->getBaseGrandTotal()),
            'items_qty' => $this->floatOrNull($totals->getItemsQty()),
            'coupon_code' => $totals->getCouponCode(),
        ];
    }

    /**
     * @param CartInterface $cart
     * @return string|null
     */
    private function currencyCode(CartInterface $cart): ?string
    {
        $currency = $cart->getCurrency();

        return $currency === null ? null : $currency->getQuoteCurrencyCode();
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
