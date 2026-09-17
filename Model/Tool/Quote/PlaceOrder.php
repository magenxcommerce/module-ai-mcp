<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Turn a cart into an order.
 *
 * The most irreversible tool this server has. It commits a customer to a
 * purchase, reserves stock against every line, and on most stores sends them a
 * confirmation e-mail — all before the call returns. Nothing here can be undone
 * from here: `cancel_order` releases the stock and closes the order, but the
 * customer has still had the e-mail, and the order still exists in the record.
 *
 * So everything that can be checked is checked first, and each refusal names
 * the tool that fixes it. The checks are cheap and the alternative is a cart
 * that failed half-way through conversion, which is the one state nothing in
 * this server can tidy up.
 *
 * It sits behind `Magento_Sales::create` — the grant Magento itself uses for
 * admin order creation — rather than the `Magento_Cart::manage` the other cart
 * tools take. Building a basket and committing somebody to buy it are different
 * permissions, and an integration that should do the first has no business
 * doing the second.
 */
class PlaceOrder extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartManagementInterface $cartManagement
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartManagementInterface $cartManagement,
        private readonly PaymentMethodManagementInterface $paymentMethodManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'place_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Turn a cart into a real order. THIS CANNOT BE UNDONE from here: it commits the '
            . 'customer to the purchase, reserves stock, and on most stores sends them an order '
            . 'confirmation e-mail immediately. cancel_order can close the order and release the '
            . 'stock afterwards, but the customer has still been e-mailed and the order still '
            . 'exists. The cart must already have its addresses (set_cart_delivery) and a payment '
            . 'method (set_cart_payment_method). Behind its own ACL resource, separate from the '
            . 'one that builds carts.';
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
        // Magento's own grant for creating an order in the admin. Deliberately
        // not Magento_Cart::manage: assembling a cart and committing a customer
        // to buy it are different permissions.
        return 'Magento_Sales::create';
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
        $cart = $this->locator->locateActive($cartId);

        $this->assertHasItems($cart, $cartId);
        $this->assertHasPaymentMethod($cartId);

        // Read the cart while it is still a cart: placing the order deactivates
        // it, and the result should be able to say what was bought.
        $placed = $this->projector->toDetail($cart);

        $orderId = (int) $this->cartManagement->placeOrder($cartId);

        return [
            'placed' => true,
            'tool' => $this->getName(),
            'order_id' => $orderId,
            'increment_id' => $this->incrementId($orderId),
            'cart_id' => $cartId,
            'cart_as_placed' => $placed,
            'note' => 'The order exists and stock is reserved. The customer has probably been '
                . 'e-mailed already. get_order reads it; cancel_order is the only way back, and '
                . 'it closes the order rather than erasing it.',
        ];
    }

    /**
     * @param CartInterface $cart
     * @param int $cartId
     * @return void
     * @throws LocalizedException
     */
    private function assertHasItems(CartInterface $cart, int $cartId): void
    {
        if (($cart->getItems() ?? []) !== []) {
            return;
        }

        throw new LocalizedException(__(
            'Cart %1 is empty, so there is nothing to order. Add products with add_cart_item.',
            $cartId
        ));
    }

    /**
     * @param int $cartId
     * @return void
     * @throws LocalizedException
     */
    private function assertHasPaymentMethod(int $cartId): void
    {
        try {
            $payment = $this->paymentMethodManagement->get($cartId);
        } catch (\Throwable) {
            $payment = null;
        }

        if ($payment !== null && (string) $payment->getMethod() !== '') {
            return;
        }

        // Magento would refuse this too, but from inside the quote model and
        // with a message about a payment object rather than about the step that
        // was missed.
        throw new LocalizedException(__(
            'Cart %1 has no payment method, so it cannot be placed. Use '
            . 'list_cart_payment_methods to see what it offers, then set_cart_payment_method.',
            $cartId
        ));
    }

    /**
     * The customer-facing order number, which is not the order id.
     *
     * Reported because it is what appears in the confirmation e-mail and what
     * anybody will quote back; failing to read it must not fail the call, since
     * by this point the order exists whatever happens next.
     *
     * @param int $orderId
     * @return string|null
     */
    private function incrementId(int $orderId): ?string
    {
        try {
            return $this->orderRepository->get($orderId)->getIncrementId();
        } catch (NoSuchEntityException | \Throwable) {
            return null;
        }
    }
}
