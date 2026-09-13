<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\Data\OptionInterface;

/**
 * Shrinks a bundle option and its selections to something worth sending to a model.
 */
class BundleOptionProjector
{
    /**
     * @param OptionInterface $option
     * @return array<string, mixed>
     */
    public function toArray(OptionInterface $option): array
    {
        return [
            'option_id' => (int) $option->getOptionId(),
            'title' => $option->getTitle(),
            'type' => $option->getType(),
            'required' => (bool) $option->getRequired(),
            'position' => (int) $option->getPosition(),
            'product_links' => array_map(
                fn (LinkInterface $link): array => $this->link($link),
                array_values($option->getProductLinks() ?? [])
            ),
        ];
    }

    /**
     * One selection inside an option.
     *
     * @param LinkInterface $link
     * @return array<string, mixed>
     */
    public function link(LinkInterface $link): array
    {
        return [
            'id' => $link->getId() === null ? null : (int) $link->getId(),
            'sku' => $link->getSku(),
            'qty' => $link->getQty() === null ? null : (float) $link->getQty(),
            'position' => (int) $link->getPosition(),
            'is_default' => (bool) $link->getIsDefault(),
            'can_change_quantity' => (bool) $link->getCanChangeQuantity(),
            // Only meaningful on a bundle priced "dynamic": a fixed-price bundle
            // reads its own price and ignores these.
            'price' => $link->getPrice() === null ? null : (float) $link->getPrice(),
            'price_type' => $link->getPriceType() === null ? null : (int) $link->getPriceType(),
        ];
    }
}
