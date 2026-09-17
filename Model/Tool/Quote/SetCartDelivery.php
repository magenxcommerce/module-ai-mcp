<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\BillingAddressManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentMethodInterface;

/**
 * Set where the order goes and how it gets there.
 *
 * One tool rather than the two the roadmap sketched, because the API is one
 * call: `ShippingInformationManagementInterface::saveAddressInformation()` takes
 * the billing address, the shipping address and the carrier/method together and
 * returns what the cart can then be paid with. That is not an inconvenience to
 * work around — it is the only order Magento supports. A shipping method cannot
 * be chosen before the destination is known, because the rate depends on it.
 *
 * **A virtual cart takes a different path entirely.** A cart of only virtual or
 * downloadable products ships nothing, has no shipping address and no carrier,
 * and `saveAddressInformation` on one fails somewhere inside shipping
 * estimation. So the tool asks the cart which it is — `getIsVirtual()` — and
 * assigns the billing address alone. Branching here rather than letting Magento
 * fail is the difference between "this cart needs no shipping method" and a
 * stack trace about an address that does not exist.
 */
class SetCartDelivery extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartAddressArguments $addressArguments
     * @param ShippingInformationInterfaceFactory $shippingInformationFactory
     * @param ShippingInformationManagementInterface $shippingInformationManagement
     * @param BillingAddressManagementInterface $billingAddressManagement
     * @param CartProjector $projector
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartAddressArguments $addressArguments,
        private readonly ShippingInformationInterfaceFactory $shippingInformationFactory,
        private readonly ShippingInformationManagementInterface $shippingInformationManagement,
        private readonly BillingAddressManagementInterface $billingAddressManagement,
        private readonly CartProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_cart_delivery';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set a cart\'s billing address, shipping address and shipping method in one call — '
            . 'Magento takes them together, because a shipping rate depends on where it is going. '
            . 'Call estimate_cart_shipping first to get a valid carrier_code and method_code. A '
            . 'cart of only virtual or downloadable products needs no shipping at all: pass the '
            . 'billing address alone and omit the rest. On a guest cart put the customer\'s '
            . 'e-mail on the billing address, or the order cannot be confirmed to anyone.';
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
                    'billing_address' => $this->addressArguments->schemaProperties(),
                    'shipping_address' => array_merge(
                        $this->addressArguments->schemaProperties(),
                        ['description' => 'Where the parcel goes. Omit to ship to the billing '
                            . 'address, or on a virtual cart, which ships nothing.']
                    ),
                    'carrier_code' => [
                        'type' => 'string',
                        'description' => 'Carrier from estimate_cart_shipping, e.g. "flatrate". '
                            . 'Not used on a virtual cart.',
                    ],
                    'method_code' => [
                        'type' => 'string',
                        'description' => 'Method from estimate_cart_shipping, e.g. "flatrate". '
                            . 'Not used on a virtual cart.',
                    ],
                ]
            ),
            'required' => ['cart_id', 'billing_address'],
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
        // Sets the addresses and method on a cart that is not yet an order.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
    {
        // The addresses and method given replace whatever was there, so a
        // repeat lands in the same place.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $cartId = $this->requireInt($arguments, 'cart_id');
        $cart = $this->locator->locateActive($cartId);

        $billing = $this->addressArguments->build(
            $this->requireObject($arguments, 'billing_address'),
            'billing'
        );

        if ((bool) $cart->getIsVirtual()) {
            return $this->setVirtual($cartId, $billing, $arguments);
        }

        $carrier = $this->requireString($arguments, 'carrier_code');
        $method = $this->requireString($arguments, 'method_code');

        $shippingRaw = $arguments['shipping_address'] ?? null;
        $shipping = $this->addressArguments->build(
            is_array($shippingRaw) && $shippingRaw !== []
                ? $shippingRaw
                : $this->requireObject($arguments, 'billing_address'),
            'shipping'
        );

        $information = $this->shippingInformationFactory->create();
        $information->setBillingAddress($billing);
        $information->setShippingAddress($shipping);
        $information->setShippingCarrierCode($carrier);
        $information->setShippingMethodCode($method);

        $details = $this->shippingInformationManagement->saveAddressInformation($cartId, $information);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'is_virtual' => false,
            'carrier_code' => $carrier,
            'method_code' => $method,
            // The call hands back what the cart can now be paid with, so the
            // next step is answered without another round trip.
            'payment_methods_available' => $this->paymentMethods($details),
            'next_step' => 'Choose one of the payment methods above with set_cart_payment_method, '
                . 'then call place_order.',
        ] + $this->projector->toDetail($this->locator->locate($cartId));
    }

    /**
     * A cart that ships nothing: billing address only.
     *
     * @param int $cartId
     * @param AddressInterface $billing
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function setVirtual(int $cartId, AddressInterface $billing, array $arguments): array
    {
        foreach (['shipping_address', 'carrier_code', 'method_code'] as $key) {
            if (array_key_exists($key, $arguments)) {
                throw new LocalizedException(__(
                    'Cart %1 holds only virtual or downloadable products, so it ships nothing and '
                    . '"%2" does not apply to it. Pass the billing address alone.',
                    $cartId,
                    $key
                ));
            }
        }

        $this->billingAddressManagement->assign($cartId, $billing);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'is_virtual' => true,
            'carrier_code' => null,
            'method_code' => null,
            'next_step' => 'This cart ships nothing, so no shipping method is needed. Use '
                . 'list_cart_payment_methods, then set_cart_payment_method, then place_order.',
        ] + $this->projector->toDetail($this->locator->locate($cartId));
    }

    /**
     * @param object $details
     * @return array<int, array<string, mixed>>
     */
    private function paymentMethods(object $details): array
    {
        if (!method_exists($details, 'getPaymentMethods')) {
            return [];
        }

        $methods = [];
        foreach ($details->getPaymentMethods() as $method) {
            /** @var PaymentMethodInterface $method */
            $code = (string) $method->getCode();
            $methods[] = [
                'code' => $code,
                'title' => $method->getTitle(),
                'usable_here' => in_array($code, OfflinePaymentMethods::CODES, true),
            ];
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function requireObject(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;
        if (!is_array($value) || $value === []) {
            throw new LocalizedException(__('The "%1" argument is required and must be an object.', $key));
        }

        return $value;
    }
}
