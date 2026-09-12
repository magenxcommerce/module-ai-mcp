<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * The address fields shared by create_customer_address and
 * update_customer_address.
 *
 * Deliberately does not police which fields are mandatory: Magento decides that
 * per country and per store, and duplicating the rules here would mean refusing
 * addresses the store would have accepted. Anything missing comes back from the
 * repository as an input error, which the server already turns into a tool
 * error naming the field. What is checked here is shape — a street that is not
 * a list of lines, a region given in a form Magento cannot resolve — because
 * those fail as a bad save rather than as a readable message.
 */
class AddressArguments
{
    /**
     * @param RegionInterfaceFactory $regionFactory
     */
    public function __construct(
        private readonly RegionInterfaceFactory $regionFactory
    ) {
    }

    /**
     * Apply the fields present in the arguments to an address.
     *
     * @param AddressInterface $address
     * @param array<string, mixed> $arguments
     * @return string[] The fields that were set.
     * @throws LocalizedException
     */
    public function applyTo(AddressInterface $address, array $arguments): array
    {
        $changed = [];

        foreach ($this->scalarSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $address->{$setter}($value === null ? null : (string) $value);
            $changed[] = $key;
        }

        if (array_key_exists('street', $arguments)) {
            $address->setStreet($this->street($arguments['street']));
            $changed[] = 'street';
        }

        if (array_key_exists('region_id', $arguments) || array_key_exists('region', $arguments)) {
            $changed = array_merge($changed, $this->applyRegion($address, $arguments));
        }

        $defaultSetters = [
            'is_default_billing' => 'setIsDefaultBilling',
            'is_default_shipping' => 'setIsDefaultShipping',
        ];
        foreach ($defaultSetters as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new LocalizedException(
                    __('The "%1" argument must be true or false, not a string or a number.', $key)
                );
            }
            $address->{$setter}($arguments[$key]);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Schema fragment for the address fields.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'firstname' => ['type' => 'string'],
            'lastname' => ['type' => 'string'],
            'middlename' => ['type' => 'string'],
            'prefix' => ['type' => 'string'],
            'suffix' => ['type' => 'string'],
            'company' => ['type' => 'string'],
            'street' => [
                'type' => ['array', 'string'],
                'description' => 'Street lines. A single string is accepted and stored as one line.',
                'items' => ['type' => 'string'],
            ],
            'city' => ['type' => 'string'],
            'region' => [
                'type' => 'string',
                'description' => 'Region or state name. For a country whose regions Magento knows, '
                    . 'pass region_id instead — a name alone is stored as free text and will not '
                    . 'match the store\'s region list.',
            ],
            'region_id' => [
                'type' => 'integer',
                'description' => 'Magento\'s numeric region id, required by countries that have a '
                    . 'fixed region list.',
            ],
            'postcode' => ['type' => 'string'],
            'country_id' => [
                'type' => 'string',
                'description' => 'Two-letter ISO country code, e.g. "DE", "FR", "SA".',
            ],
            'telephone' => ['type' => 'string'],
            'fax' => ['type' => 'string'],
            'vat_id' => ['type' => 'string'],
            'is_default_billing' => [
                'type' => 'boolean',
                'description' => 'Make this the customer\'s default billing address.',
            ],
            'is_default_shipping' => [
                'type' => 'boolean',
                'description' => 'Make this the customer\'s default shipping address.',
            ],
        ];
    }

    /**
     * Plain string setters, by argument name.
     *
     * @return array<string, string>
     */
    private function scalarSetters(): array
    {
        return [
            'firstname' => 'setFirstname',
            'lastname' => 'setLastname',
            'middlename' => 'setMiddlename',
            'prefix' => 'setPrefix',
            'suffix' => 'setSuffix',
            'company' => 'setCompany',
            'city' => 'setCity',
            'postcode' => 'setPostcode',
            'country_id' => 'setCountryId',
            'telephone' => 'setTelephone',
            'fax' => 'setFax',
            'vat_id' => 'setVatId',
        ];
    }

    /**
     * Normalise the street argument into the list of lines Magento stores.
     *
     * @param mixed $street
     * @return string[]
     * @throws LocalizedException
     */
    private function street(mixed $street): array
    {
        if (is_string($street)) {
            $street = [$street];
        }
        if (!is_array($street) || $street === []) {
            throw new LocalizedException(
                __('The "street" argument must be a street line or a list of street lines.')
            );
        }

        $lines = [];
        foreach ($street as $line) {
            if (!is_string($line) && !is_int($line) && !is_float($line)) {
                throw new LocalizedException(__('Every "street" line must be a string.'));
            }
            $lines[] = (string) $line;
        }

        return $lines;
    }

    /**
     * Set the region, keeping whichever half of it the caller did not supply.
     *
     * Magento stores the region twice — a numeric id for countries with a fixed
     * list, and a name — and resolves an address by the id when it has one.
     * Replacing only the name on an address that has an id would leave the two
     * disagreeing, so both are carried over.
     *
     * @param AddressInterface $address
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyRegion(AddressInterface $address, array $arguments): array
    {
        $changed = [];
        $existing = $address->getRegion();

        $regionId = $address->getRegionId() === null ? null : (int) $address->getRegionId();
        if (array_key_exists('region_id', $arguments)) {
            $value = $arguments['region_id'];
            if ($value !== null && !is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new LocalizedException(__('The "region_id" argument must be a whole number.'));
            }
            $regionId = $value === null ? null : (int) $value;
            $changed[] = 'region_id';
        }

        $regionName = $existing?->getRegion();
        if (array_key_exists('region', $arguments)) {
            $value = $arguments['region'];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(__('The "region" argument must be a string.'));
            }
            $regionName = $value;
            $changed[] = 'region';
        }

        $region = $this->regionFactory->create();
        $region->setRegionId($regionId);
        $region->setRegion($regionName);
        $region->setRegionCode($existing?->getRegionCode());
        $address->setRegion($region);
        $address->setRegionId($regionId);

        return $changed;
    }
}
