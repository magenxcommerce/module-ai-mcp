<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;

/**
 * What this cart could be paid with, and what this server can actually use.
 *
 * Reports every method Magento offers the cart and marks each one usable or
 * not, rather than quietly listing only the offline ones. An agent that saw a
 * short list with no explanation would reasonably conclude the store had almost
 * no payment methods configured; what is true is narrower and worth saying —
 * the store has them, and this server cannot drive them.
 */
class ListCartPaymentMethods extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly PaymentMethodManagementInterface $paymentMethodManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_cart_payment_methods';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the payment methods available for a cart, marking which of them this server '
            . 'can actually use. Only offline methods — check/money order, bank transfer, cash on '
            . 'delivery, purchase order, and the zero-total method — can be driven from here; an '
            . 'online gateway needs the customer at a payment page. Methods are still listed when '
            . 'unusable, so you can see what the store offers rather than assuming it offers '
            . 'nothing.';
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
        $cartId = $this->requireInt($arguments, 'cart_id');
        $this->locator->locate($cartId);

        $items = [];
        $usable = 0;
        foreach ($this->paymentMethodManagement->getList($cartId) as $method) {
            /** @var PaymentMethodInterface $method */
            $code = (string) $method->getCode();
            $offline = in_array($code, OfflinePaymentMethods::CODES, true);
            $usable += $offline ? 1 : 0;

            $items[] = [
                'code' => $code,
                'title' => $method->getTitle(),
                'usable_here' => $offline,
                'reason' => $offline
                    ? null
                    : 'Online gateway — completing it needs the customer at a payment page.',
            ];
        }

        return [
            'cart_id' => $cartId,
            'items' => $items,
            'usable_count' => $usable,
            'note' => $usable === 0
                ? 'No method on this cart can be driven from here. To take payment, send the '
                    . 'customer a link to check out themselves.'
                : 'set_cart_payment_method accepts the methods marked usable_here.',
        ];
    }
}
