<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;

/**
 * Shrinks a product attribute to something worth sending to a model.
 *
 * The option list is the reason the detail view is opt-in: a colour or size
 * attribute on a large catalogue carries hundreds of options, and a listing
 * that included them would be mostly option labels.
 */
class AttributeProjector
{
    /**
     * @param ProductAttributeInterface $attribute
     * @return array<string, mixed>
     */
    public function toSummary(ProductAttributeInterface $attribute): array
    {
        return [
            'attribute_id' => $attribute->getAttributeId() === null
                ? null
                : (int) $attribute->getAttributeId(),
            'attribute_code' => $attribute->getAttributeCode(),
            'default_frontend_label' => $attribute->getDefaultFrontendLabel(),
            'frontend_input' => $attribute->getFrontendInput(),
            'backend_type' => $attribute->getBackendType(),
            'is_required' => (bool) $attribute->getIsRequired(),
            'is_user_defined' => (bool) $attribute->getIsUserDefined(),
            // 'global', 'website' or 'store': which scope a value belongs to.
            'scope' => $attribute->getScope(),
        ];
    }

    /**
     * @param ProductAttributeInterface $attribute
     * @param bool $includeOptions
     * @return array<string, mixed>
     */
    public function toDetail(ProductAttributeInterface $attribute, bool $includeOptions): array
    {
        $detail = $this->toSummary($attribute);
        $detail['note'] = $attribute->getNote();
        $detail['default_value'] = $attribute->getDefaultValue();
        $detail['is_unique'] = (bool) $attribute->getIsUnique();
        $detail['apply_to'] = $attribute->getApplyTo();
        $detail['storefront'] = [
            'is_searchable' => (bool) $attribute->getIsSearchable(),
            'is_visible_in_advanced_search' => (bool) $attribute->getIsVisibleInAdvancedSearch(),
            'is_comparable' => (bool) $attribute->getIsComparable(),
            'is_filterable' => (bool) $attribute->getIsFilterable(),
            'is_filterable_in_search' => (bool) $attribute->getIsFilterableInSearch(),
            'is_visible_on_front' => (bool) $attribute->getIsVisibleOnFront(),
            'used_in_product_listing' => (bool) $attribute->getUsedInProductListing(),
            'used_for_sort_by' => (bool) $attribute->getUsedForSortBy(),
            'is_used_for_promo_rules' => (bool) $attribute->getIsUsedForPromoRules(),
        ];
        $detail['admin_grid'] = [
            'is_used_in_grid' => (bool) $attribute->getIsUsedInGrid(),
            'is_visible_in_grid' => (bool) $attribute->getIsVisibleInGrid(),
            'is_filterable_in_grid' => (bool) $attribute->getIsFilterableInGrid(),
        ];

        $options = $attribute->getOptions() ?? [];
        if ($includeOptions) {
            $detail['options'] = array_values(array_map(
                static fn (AttributeOptionInterface $option): array => [
                    'value' => $option->getValue(),
                    'label' => $option->getLabel(),
                    'sort_order' => $option->getSortOrder() === null ? null : (int) $option->getSortOrder(),
                    'is_default' => (bool) $option->getIsDefault(),
                ],
                $options
            ));
        } else {
            $detail['options_omitted'] = [
                'count' => count($options),
                'reason' => 'Pass include_options to read the option list.',
            ];
        }

        return $detail;
    }
}
