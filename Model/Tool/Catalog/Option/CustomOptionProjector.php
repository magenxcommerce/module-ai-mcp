<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog\Option;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;

/**
 * Shrinks a product's custom option to something worth sending to a model.
 */
class CustomOptionProjector
{
    /**
     * @param ProductCustomOptionInterface $option
     * @return array<string, mixed>
     */
    public function toArray(ProductCustomOptionInterface $option): array
    {
        $data = [
            'option_id' => (int) $option->getOptionId(),
            'title' => $option->getTitle(),
            'type' => $option->getType(),
            'is_require' => (bool) $option->getIsRequire(),
            'sort_order' => (int) $option->getSortOrder(),
            'price' => $option->getPrice() === null ? null : (float) $option->getPrice(),
            // "fixed" is an amount; "percent" is a share of the product price.
            'price_type' => $option->getPriceType(),
            'sku' => $option->getSku(),
        ];

        $values = $option->getValues();
        if (is_array($values) && $values !== []) {
            $data['values'] = array_map(
                static fn (ProductCustomOptionValuesInterface $value): array => [
                    'option_type_id' => $value->getOptionTypeId() === null
                        ? null
                        : (int) $value->getOptionTypeId(),
                    'title' => $value->getTitle(),
                    'sort_order' => (int) $value->getSortOrder(),
                    'price' => $value->getPrice() === null ? null : (float) $value->getPrice(),
                    'price_type' => $value->getPriceType(),
                    'sku' => $value->getSku(),
                ],
                array_values($values)
            );
        }

        return $data;
    }
}
