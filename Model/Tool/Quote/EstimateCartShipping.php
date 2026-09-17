<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShipmentEstimationInterface;

/**
 * What it would cost to ship this cart to a given address.
 *
 * Has to come before set_cart_delivery, because that call takes a carrier and
 * method code and Magento will not tell you which ones are valid until it knows
 * where the parcel is going — rates depend on the destination, the weight and
 * the cart total all at once. Guessing a code produces a refusal from deep
 * inside the shipping module rather than a list of alternatives.
 *
 * Estimating changes nothing: the address passed here is used to price the
 * options and is not stored on the cart.
 */
class EstimateCartShipping extends AbstractTool
{
    /**
     * @param CartLocator $locator
     * @param CartAddressArguments $addressArguments
     * @param ShipmentEstimationInterface $shipmentEstimation
     */
    public function __construct(
        private readonly CartLocator $locator,
        private readonly CartAddressArguments $addressArguments,
        private readonly ShipmentEstimationInterface $shipmentEstimation
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'estimate_cart_shipping';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the shipping options available for a cart to a given address, with their '
            . 'prices and the carrier_code / method_code pair that set_cart_delivery takes. Call '
            . 'this first: shipping rates depend on the destination, so Magento cannot say which '
            . 'method codes are valid until it has one. This changes nothing — the address is used '
            . 'to price the options and is not saved to the cart. A cart of only virtual or '
            . 'downloadable products ships nothing and returns an empty list.';
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
                    'address' => array_merge(
                        $this->addressArguments->schemaProperties(),
                        ['description' => 'Where the parcel would go. Country and postcode are '
                            . 'usually enough to price it.']
                    ),
                ]
            ),
            'required' => ['cart_id', 'address'],
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
        $cart = $this->locator->locateActive($cartId);

        if ((bool) $cart->getIsVirtual()) {
            return [
                'cart_id' => $cartId,
                'is_virtual' => true,
                'items' => [],
                'note' => 'This cart holds only virtual or downloadable products, so nothing is '
                    . 'shipped and no method is needed. set_cart_delivery takes a billing address '
                    . 'alone for a cart like this.',
            ];
        }

        $address = $this->addressArguments->build($this->requireArray($arguments, 'address'), 'shipping');
        $methods = $this->shipmentEstimation->estimateByExtendedAddress($cartId, $address);

        $items = [];
        foreach ($methods as $method) {
            /** @var ShippingMethodInterface $method */
            $items[] = [
                'carrier_code' => $method->getCarrierCode(),
                'method_code' => $method->getMethodCode(),
                'carrier_title' => $method->getCarrierTitle(),
                'method_title' => $method->getMethodTitle(),
                'amount' => $method->getAmount() === null ? null : (float) $method->getAmount(),
                'price_incl_tax' => $method->getPriceInclTax() === null
                    ? null
                    : (float) $method->getPriceInclTax(),
                'available' => (bool) $method->getAvailable(),
                // A carrier that answered with a refusal rather than a rate.
                // Reported rather than filtered out: "UPS is not offered" and
                // "UPS said the postcode is wrong" need different fixes.
                'error_message' => $method->getErrorMessage(),
            ];
        }

        return [
            'cart_id' => $cartId,
            'is_virtual' => false,
            'items' => $items,
            'note' => $items === []
                ? 'No carrier offered a rate to this address. Check the country is one the store '
                    . 'ships to and that a shipping method is enabled for it.'
                : 'Pass a carrier_code and method_code from this list to set_cart_delivery.',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function requireArray(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;
        if (!is_array($value) || $value === []) {
            throw new LocalizedException(__('The "%1" argument is required and must be an object.', $key));
        }

        return $value;
    }
}
