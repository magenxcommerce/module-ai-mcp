<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * The flags and labels shared by create_product_attribute and
 * update_product_attribute.
 *
 * Everything here is a plain setting on the attribute. The two things that are
 * deliberately absent are the attribute code and the frontend input type:
 * Magento cannot change either on an existing attribute — the code is its
 * identity and the input type decides the storage column — so only the create
 * tool accepts them.
 */
class AttributeFlagArguments
{
    /**
     * Apply the fields present in the arguments to an attribute.
     *
     * @param ProductAttributeInterface $attribute
     * @param array<string, mixed> $arguments
     * @return string[] The fields that were set.
     * @throws LocalizedException
     */
    public function applyTo(ProductAttributeInterface $attribute, array $arguments): array
    {
        $changed = [];

        foreach ($this->stringSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $attribute->{$setter}($value);
            $changed[] = $key;
        }

        foreach ($this->booleanSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new LocalizedException(
                    __('The "%1" argument must be true or false, not a string or a number.', $key)
                );
            }
            $attribute->{$setter}($arguments[$key]);
            $changed[] = $key;
        }

        if (array_key_exists('scope', $arguments)) {
            $scope = $arguments['scope'];
            $allowed = ['global', 'website', 'store'];
            if (!is_string($scope) || !in_array($scope, $allowed, true)) {
                throw new LocalizedException(
                    __('The "scope" argument must be one of: %1.', implode(', ', $allowed))
                );
            }
            $attribute->setScope($scope);
            $changed[] = 'scope';
        }

        return $changed;
    }

    /**
     * Schema fragment for the shared fields.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'default_frontend_label' => [
                'type' => 'string',
                'description' => 'The label shown in the admin and, where visible, the storefront.',
            ],
            'note' => ['type' => 'string', 'description' => 'Hint text shown under the field in the admin.'],
            'default_value' => [
                'type' => ['string', 'null'],
                'description' => 'Value used when a product does not set one. For a dropdown this '
                    . 'is an option id, not a label.',
            ],
            'scope' => [
                'type' => 'string',
                'enum' => ['global', 'website', 'store'],
                'description' => 'Which scope a value belongs to. "global" means one value '
                    . 'everywhere; "store" allows a different value per store view, which is what '
                    . 'a translatable attribute needs.',
            ],
            'is_required' => ['type' => 'boolean', 'description' => 'Whether a product must have a value.'],
            'is_unique' => ['type' => 'boolean', 'description' => 'Whether no two products may share a value.'],
            'is_searchable' => ['type' => 'boolean', 'description' => 'Include in storefront search.'],
            'is_visible_in_advanced_search' => ['type' => 'boolean'],
            'is_comparable' => ['type' => 'boolean', 'description' => 'Show in product comparison.'],
            'is_filterable' => [
                'type' => 'boolean',
                'description' => 'Offer as a layered-navigation filter on category pages.',
            ],
            'is_filterable_in_search' => ['type' => 'boolean', 'description' => 'Offer as a filter on search results.'],
            'is_visible_on_front' => [
                'type' => 'boolean',
                'description' => 'Show in the product page\'s additional information table.',
            ],
            'used_in_product_listing' => [
                'type' => 'boolean',
                'description' => 'Make available to category listings. Adds the attribute to the '
                    . 'flat/listing data, so turning it on for many attributes costs performance.',
            ],
            'used_for_sort_by' => ['type' => 'boolean', 'description' => 'Offer as a storefront sort option.'],
            'is_used_for_promo_rules' => [
                'type' => 'boolean',
                'description' => 'Make available as a condition in cart and catalog price rules.',
            ],
            'is_used_in_grid' => ['type' => 'boolean', 'description' => 'Available as an admin product grid column.'],
            'is_visible_in_grid' => ['type' => 'boolean', 'description' => 'Shown by default in the admin grid.'],
            'is_filterable_in_grid' => ['type' => 'boolean', 'description' => 'Filterable in the admin grid.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function stringSetters(): array
    {
        return [
            'default_frontend_label' => 'setDefaultFrontendLabel',
            'note' => 'setNote',
            'default_value' => 'setDefaultValue',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function booleanSetters(): array
    {
        return [
            'is_required' => 'setIsRequired',
            'is_unique' => 'setIsUnique',
            'is_searchable' => 'setIsSearchable',
            'is_visible_in_advanced_search' => 'setIsVisibleInAdvancedSearch',
            'is_comparable' => 'setIsComparable',
            'is_filterable' => 'setIsFilterable',
            'is_filterable_in_search' => 'setIsFilterableInSearch',
            'is_visible_on_front' => 'setIsVisibleOnFront',
            'used_in_product_listing' => 'setUsedInProductListing',
            'used_for_sort_by' => 'setUsedForSortBy',
            'is_used_for_promo_rules' => 'setIsUsedForPromoRules',
            'is_used_in_grid' => 'setIsUsedInGrid',
            'is_visible_in_grid' => 'setIsVisibleInGrid',
            'is_filterable_in_grid' => 'setIsFilterableInGrid',
        ];
    }
}
