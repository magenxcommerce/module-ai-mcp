<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentInterfaceFactory;
use Magento\Quote\Api\PaymentMethodManagementInterface;

/**
 * Choose how the order will be paid for.
 *
 * Restricted to offline methods, and {@see OfflinePaymentMethods} carries the
 * reasoning and the two refusals. What matters here is that the check happens
 * *before* the method is set: an online gateway assigned to a cart is not inert,
 * it is a cart that will fail part-way through placeOrder.
 *
 * `purchaseorder` additionally needs a PO number. `PaymentInterface` carries one
 * as a first-class field, and Magento's own validator rejects a missing one
 * late and obscurely — so the schema asks for it and this refuses without it,
 * naming the method that needs it.
 */
class SetCartPaymentMethod extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param OfflinePaymentMethods $offlineMethods
     * @param PaymentInterfaceFactory $paymentFactory
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly OfflinePaymentMethods $offlineMethods,
        private readonly PaymentInterfaceFactory $paymentFactory,
        private readonly PaymentMethodManagementInterface $paymentMethodManagement,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_cart_payment_method';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Choose the payment method for a cart. Only offline methods can be used through '
            . 'this server — check/money order, bank transfer, cash on delivery, purchase order, '
            . 'and the zero-total method — because an online gateway needs the customer at a '
            . 'payment page. An online method is refused before it is set, since assigning one '
            . 'would fail part-way through place_order instead. Use list_cart_payment_methods to '
            . 'see what this cart offers. A purchase order also needs po_number.';
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
                    'method' => [
                        'type' => 'string',
                        'enum' => OfflinePaymentMethods::CODES,
                        'description' => 'Payment method code. Only these five can be driven from '
                            . 'here, and the store may not offer all of them.',
                    ],
                    'po_number' => [
                        'type' => 'string',
                        'description' => 'Purchase order number. Required when method is '
                            . '"purchaseorder", ignored otherwise.',
                    ],
                ]
            ),
            'required' => ['cart_id', 'method'],
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
        // Sets a field on a cart that is not yet an order. Nothing is charged
        // until place_order.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $cartId = $this->requireInt($arguments, 'cart_id');
        $this->locator->locateActive($cartId);

        $method = $this->requireString($arguments, 'method');
        $this->offlineMethods->assertUsable($cartId, $method);

        $payment = $this->paymentFactory->create();
        $payment->setMethod($method);

        if ($method === OfflinePaymentMethods::PURCHASE_ORDER) {
            $payment->setPoNumber($this->purchaseOrderNumber($arguments));
        }

        $this->paymentMethodManagement->set($cartId, $payment);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'method' => $method,
            'next_step' => 'Call place_order to commit this cart. That is the irreversible step — '
                . 'it creates the order and reserves stock.',
        ] + $this->projector->toDetail($this->locator->locate($cartId));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function purchaseOrderNumber(array $arguments): string
    {
        $poNumber = $this->optionalString($arguments, 'po_number');

        if ($poNumber === null || trim($poNumber) === '') {
            throw new LocalizedException(__(
                'The "purchaseorder" method needs a "po_number" — it is what the order is billed '
                . 'against, and Magento rejects the payment without one.'
            ));
        }

        return trim($poNumber);
    }
}
