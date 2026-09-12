<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;

/**
 * Shrinks a customer to something worth sending to a model.
 *
 * Everything here is personal data, so the two projections differ in more than
 * size: the summary carries what identifies an account for an operator working
 * through a list, and the detail view adds the rest — date of birth, gender,
 * tax/VAT id, postal addresses, phone numbers — which only get sent when a tool
 * was asked about one specific customer.
 */
class CustomerProjector
{
    /**
     * What identifies an account.
     *
     * @param CustomerInterface $customer
     * @return array<string, mixed>
     */
    public function toSummary(CustomerInterface $customer): array
    {
        return [
            'customer_id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'name' => $this->name($customer),
            'group_id' => $customer->getGroupId() === null ? null : (int) $customer->getGroupId(),
            'website_id' => $customer->getWebsiteId() === null ? null : (int) $customer->getWebsiteId(),
            'store_id' => $customer->getStoreId() === null ? null : (int) $customer->getStoreId(),
            // Non-null means the account was created but never confirmed, so
            // the customer cannot sign in yet.
            'confirmation_pending' => $customer->getConfirmation() !== null && $customer->getConfirmation() !== '',
            'created_at' => $customer->getCreatedAt(),
            'updated_at' => $customer->getUpdatedAt(),
        ];
    }

    /**
     * The summary plus the remaining profile fields and every address.
     *
     * @param CustomerInterface $customer
     * @return array<string, mixed>
     */
    public function toDetail(CustomerInterface $customer): array
    {
        $detail = $this->toSummary($customer);
        $detail['firstname'] = $customer->getFirstname();
        $detail['lastname'] = $customer->getLastname();
        $detail['middlename'] = $customer->getMiddlename();
        $detail['prefix'] = $customer->getPrefix();
        $detail['suffix'] = $customer->getSuffix();
        $detail['dob'] = $customer->getDob();
        // An option id, not a label: 1 = Male, 2 = Female, 3 = Not specified on
        // a default installation, and editable per store.
        $detail['gender'] = $customer->getGender() === null ? null : (int) $customer->getGender();
        $detail['taxvat'] = $customer->getTaxvat();
        $detail['created_in'] = $customer->getCreatedIn();
        $detail['disable_auto_group_change'] = $customer->getDisableAutoGroupChange() === null
            ? null
            : (bool) $customer->getDisableAutoGroupChange();
        $detail['default_billing_address_id'] = $customer->getDefaultBilling() === null
            ? null
            : (int) $customer->getDefaultBilling();
        $detail['default_shipping_address_id'] = $customer->getDefaultShipping() === null
            ? null
            : (int) $customer->getDefaultShipping();

        $detail['addresses'] = array_map(
            fn (AddressInterface $address): array => $this->address($address),
            array_values($customer->getAddresses() ?? [])
        );

        return $detail;
    }

    /**
     * One address, with the id the address tools take.
     *
     * @param AddressInterface $address
     * @return array<string, mixed>
     */
    public function address(AddressInterface $address): array
    {
        $region = $address->getRegion();

        return [
            'address_id' => (int) $address->getId(),
            'customer_id' => $address->getCustomerId() === null ? null : (int) $address->getCustomerId(),
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $region?->getRegion(),
            'region_id' => $address->getRegionId() === null ? null : (int) $address->getRegionId(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
            'vat_id' => $address->getVatId(),
            'is_default_billing' => (bool) $address->isDefaultBilling(),
            'is_default_shipping' => (bool) $address->isDefaultShipping(),
        ];
    }

    /**
     * @param CustomerInterface $customer
     * @return string|null
     */
    private function name(CustomerInterface $customer): ?string
    {
        $name = trim((string) $customer->getFirstname() . ' ' . (string) $customer->getLastname());

        return $name === '' ? null : $name;
    }
}
