<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * The profile fields shared by create_customer and update_customer.
 *
 * Kept away from the account's identity — email, website, password — which each
 * tool handles itself, because those three are the ones whose meaning differs
 * between creating an account and editing one.
 */
class CustomerProfileArguments
{
    /**
     * Apply the fields present in the arguments to a customer.
     *
     * @param CustomerInterface $customer
     * @param array<string, mixed> $arguments
     * @return string[] The fields that were set.
     * @throws LocalizedException
     */
    public function applyTo(CustomerInterface $customer, array $arguments): array
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
            $customer->{$setter}($value === null ? null : (string) $value);
            $changed[] = $key;
        }

        foreach (['group_id' => 'setGroupId', 'store_id' => 'setStoreId', 'gender' => 'setGender'] as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new LocalizedException(__('The "%1" argument must be a whole number.', $key));
            }
            $customer->{$setter}((int) $value);
            $changed[] = $key;
        }

        if (array_key_exists('disable_auto_group_change', $arguments)) {
            $value = $arguments['disable_auto_group_change'];
            if (!is_bool($value)) {
                throw new LocalizedException(__(
                    'The "%1" argument must be true or false, not a string or a number.',
                    'disable_auto_group_change'
                ));
            }
            $customer->setDisableAutoGroupChange($value ? 1 : 0);
            $changed[] = 'disable_auto_group_change';
        }

        foreach ($this->customAttributes($arguments) as $code => $value) {
            $customer->setCustomAttribute($code, $value);
            $changed[] = $code;
        }

        return $changed;
    }

    /**
     * Schema fragment for the profile fields.
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
            'dob' => ['type' => 'string', 'description' => 'Date of birth as "YYYY-MM-DD".'],
            'gender' => [
                'type' => 'integer',
                'description' => 'Gender option id, not a label. On a default installation 1 = Male, '
                    . '2 = Female, 3 = Not Specified, but the option list is editable, so confirm '
                    . 'with list_product_attributes on the "gender" attribute if it matters.',
            ],
            'taxvat' => ['type' => 'string', 'description' => 'Tax/VAT number.'],
            'group_id' => [
                'type' => 'integer',
                'description' => 'Customer group. list_customer_groups reports the ids; an unknown '
                    . 'id is refused.',
            ],
            'store_id' => [
                'type' => 'integer',
                'description' => 'Store view the account is associated with, which decides the '
                    . 'language of emails sent to it. list_stores reports the ids.',
            ],
            'disable_auto_group_change' => [
                'type' => 'boolean',
                'description' => 'Stop Magento reassigning the group automatically from VAT '
                    . 'validation.',
            ],
            'custom_attributes' => [
                'type' => 'object',
                'description' => 'Any other EAV attribute, by code. Advanced: values are passed '
                    . 'through to Magento unchanged, so a wrong option id fails the save.',
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
            'dob' => 'setDob',
            'taxvat' => 'setTaxvat',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function customAttributes(array $arguments): array
    {
        $attributes = $arguments['custom_attributes'] ?? null;
        if (!is_array($attributes)) {
            return [];
        }

        $valid = [];
        foreach ($attributes as $code => $value) {
            if (is_string($code) && $code !== '') {
                $valid[$code] = $value;
            }
        }

        return $valid;
    }
}
