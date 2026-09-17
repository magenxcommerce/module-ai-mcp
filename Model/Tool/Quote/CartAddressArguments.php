<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;

/**
 * Builds the billing and shipping addresses a cart is checked out against.
 *
 * Deliberately does not police which fields are mandatory — the same decision
 * {@see \Magenx\AiMcp\Model\Tool\Customer\AddressArguments} documents, and for
 * the same reason: Magento decides that per country and per store, and
 * duplicating the rules here would mean refusing addresses the store would have
 * accepted. What is checked is shape, because a street that is not a list of
 * lines fails as a bad save rather than as a readable message.
 *
 * A quote address is not a customer address, which is why this is a separate
 * class rather than a reuse. It carries `email` — a guest cart has nowhere else
 * to put one — and `same_as_billing`, and it has no notion of being anybody's
 * default.
 */
class CartAddressArguments
{
    /**
     * @param AddressInterfaceFactory $addressFactory
     */
    public function __construct(private readonly AddressInterfaceFactory $addressFactory)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $label Which address this is, for the error messages.
     * @return AddressInterface
     * @throws LocalizedException
     */
    public function build(array $arguments, string $label): AddressInterface
    {
        $address = $this->addressFactory->create();

        foreach ($this->stringSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_string($arguments[$key])) {
                throw new LocalizedException(
                    __('The %1 address field "%2" must be a string.', $label, $key)
                );
            }
            $address->{$setter}($arguments[$key]);
        }

        if (array_key_exists('street', $arguments)) {
            $address->setStreet($this->street($arguments['street'], $label));
        }

        if (array_key_exists('region_id', $arguments)) {
            $regionId = $arguments['region_id'];
            if (!is_int($regionId) && !(is_string($regionId) && ctype_digit($regionId))) {
                throw new LocalizedException(
                    __('The %1 address field "region_id" must be a whole number.', $label)
                );
            }
            $address->setRegionId((int) $regionId);
        }

        return $address;
    }

    /**
     * Street lines, accepting the single-line form as the customer tools do.
     *
     * @param mixed $value
     * @param string $label
     * @return string[]
     * @throws LocalizedException
     */
    private function street(mixed $value, string $label): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value) || $value === []) {
            throw new LocalizedException(__(
                'The %1 address field "street" must be a line or a list of lines.',
                $label
            ));
        }

        $lines = [];
        foreach ($value as $line) {
            if (!is_string($line)) {
                throw new LocalizedException(
                    __('Every line of the %1 address "street" must be a string.', $label)
                );
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function stringSetters(): array
    {
        return [
            'firstname' => 'setFirstname',
            'lastname' => 'setLastname',
            'middlename' => 'setMiddlename',
            'prefix' => 'setPrefix',
            'suffix' => 'setSuffix',
            'company' => 'setCompany',
            'city' => 'setCity',
            'region' => 'setRegion',
            'region_code' => 'setRegionCode',
            'postcode' => 'setPostcode',
            'country_id' => 'setCountryId',
            'telephone' => 'setTelephone',
            'fax' => 'setFax',
            'vat_id' => 'setVatId',
            'email' => 'setEmail',
        ];
    }

    /**
     * Schema fragment for one address.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'middlename' => ['type' => 'string'],
                'prefix' => ['type' => 'string'],
                'suffix' => ['type' => 'string'],
                'company' => ['type' => 'string'],
                'street' => [
                    'type' => ['array', 'string'],
                    'description' => 'Street lines. A single string is accepted as one line.',
                    'items' => ['type' => 'string'],
                ],
                'city' => ['type' => 'string'],
                'region' => [
                    'type' => 'string',
                    'description' => 'Region or state name. For a country whose regions Magento '
                        . 'knows, pass region_id instead — a name alone is stored as free text.',
                ],
                'region_id' => [
                    'type' => 'integer',
                    'description' => 'Magento\'s numeric region id, required by countries with a '
                        . 'fixed region list.',
                ],
                'region_code' => ['type' => 'string', 'description' => 'Region code, e.g. "CA".'],
                'postcode' => ['type' => 'string'],
                'country_id' => [
                    'type' => 'string',
                    'description' => 'Two-letter ISO country code, e.g. "DE", "FR", "SA".',
                ],
                'telephone' => ['type' => 'string'],
                'fax' => ['type' => 'string'],
                'vat_id' => ['type' => 'string'],
                'email' => [
                    'type' => 'string',
                    'description' => 'Where the order confirmation goes. Required on a guest cart, '
                        . 'which has no customer account to take it from.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
