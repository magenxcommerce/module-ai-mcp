<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;

/**
 * Which payment methods an agent may actually choose.
 *
 * This server can only place an order against an **offline** method. An online
 * gateway finishes at a payment page the customer has to be standing in front
 * of — a redirect, a 3-D Secure challenge, a hosted field that never sees this
 * process — and no MCP tool can stand in for them. Selecting one does not fail
 * cleanly either: it fails somewhere inside the payment integration during
 * placeOrder, by which time a quote may be half converted.
 *
 * So the check is an intersection of two real lists, and the two things it can
 * refuse are refused differently because the fixes differ.
 *
 *  - A method this cart offers that is not offline: refused because it needs
 *    the customer, and no configuration change will alter that.
 *  - An offline method this cart does not offer: refused because the store has
 *    it switched off, restricted to other countries, or ruled out by the cart's
 *    total — which an operator can change.
 *
 * Either list alone would produce a wrong message. The allowlist alone would
 * offer a method the store has disabled, and fail at placeOrder with Magento's
 * own wording; availability alone would let an agent pick a gateway.
 *
 * The five codes are read off Magento's own modules rather than recalled:
 * `Magento_OfflinePayments` registers check/money order, bank transfer, cash on
 * delivery and purchase order, and `Magento_Payment` registers the zero-total
 * method.
 */
class OfflinePaymentMethods
{
    /** Every offline method Magento ships. */
    public const CODES = ['checkmo', 'banktransfer', 'cashondelivery', 'purchaseorder', 'free'];

    /** The one offline method that will not save without a further field. */
    public const PURCHASE_ORDER = 'purchaseorder';

    /**
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     */
    public function __construct(
        private readonly PaymentMethodManagementInterface $paymentMethodManagement
    ) {
    }

    /**
     * The offline methods this cart can actually use, as code => title.
     *
     * @param int $cartId
     * @return array<string, string>
     */
    public function availableFor(int $cartId): array
    {
        $offline = [];

        foreach ($this->paymentMethodManagement->getList($cartId) as $method) {
            /** @var PaymentMethodInterface $method */
            $code = (string) $method->getCode();
            if (in_array($code, self::CODES, true)) {
                $offline[$code] = (string) $method->getTitle();
            }
        }

        return $offline;
    }

    /**
     * Refuse anything this server cannot see through to a placed order.
     *
     * @param int $cartId
     * @param string $code
     * @return void
     * @throws LocalizedException
     */
    public function assertUsable(int $cartId, string $code): void
    {
        $offline = $this->availableFor($cartId);

        if (array_key_exists($code, $offline)) {
            return;
        }

        if (!in_array($code, self::CODES, true)) {
            throw new LocalizedException(__(
                'Payment method "%1" cannot be used through this server. Completing it needs the '
                . 'customer at a payment page — a redirect, a card form, a bank challenge — which '
                . 'no tool here can stand in for, and the failure would come after the cart had '
                . 'started converting. The offline methods this cart can use are: %2. To take an '
                . 'online payment, send the customer a link to check out themselves.',
                $code,
                $this->describe($offline)
            ));
        }

        throw new LocalizedException(__(
            'Payment method "%1" is offline, but this cart cannot use it — the store has it '
            . 'switched off, restricted to other countries, or ruled out by the cart total. The '
            . 'offline methods this cart can use are: %2. list_cart_payment_methods reports every '
            . 'method Magento offers it, offline or not.',
            $code,
            $this->describe($offline)
        ));
    }

    /**
     * @param array<string, string> $offline
     * @return string
     */
    private function describe(array $offline): string
    {
        if ($offline === []) {
            // Worth saying plainly: the next question is always "then which?"
            return 'none at all, so this cart cannot be paid for through this server';
        }

        $described = [];
        foreach ($offline as $code => $title) {
            $described[] = $title === '' ? $code : sprintf('%s (%s)', $code, $title);
        }

        return implode(', ', $described);
    }
}
