<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Shrinks a Magento product to something worth sending to a model.
 *
 * A product loaded through the repository serialises to hundreds of lines —
 * every EAV attribute, every media entry, every option. Twenty of them would
 * fill an agent's context with data it did not ask for and cannot use, so reads
 * return a small summary by default and the caller opts in to more.
 */
class ProductProjector
{
    /**
     * The identifying fields, always returned.
     *
     * @param ProductInterface $product
     * @return array<string, mixed>
     */
    public function toSummary(ProductInterface $product): array
    {
        return [
            'id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'type_id' => $product->getTypeId(),
            'status' => (int) $product->getStatus(),
            'visibility' => (int) $product->getVisibility(),
            'price' => $product->getPrice() === null ? null : (float) $product->getPrice(),
            'attribute_set_id' => (int) $product->getAttributeSetId(),
            'updated_at' => $product->getUpdatedAt(),
        ];
    }

    /**
     * The summary plus stock, websites, categories and any explicitly requested
     * custom attributes.
     *
     * @param ProductInterface $product
     * @param string[] $extraAttributes
     * @return array<string, mixed>
     */
    public function toDetail(ProductInterface $product, array $extraAttributes = []): array
    {
        $detail = $this->toSummary($product);
        $detail['created_at'] = $product->getCreatedAt();
        $detail['weight'] = $product->getWeight() === null ? null : (float) $product->getWeight();

        $extension = $product->getExtensionAttributes();
        if ($extension !== null) {
            $stockItem = $extension->getStockItem();
            if ($stockItem !== null) {
                $detail['stock'] = [
                    'qty' => (float) $stockItem->getQty(),
                    'is_in_stock' => (bool) $stockItem->getIsInStock(),
                    'manage_stock' => (bool) $stockItem->getManageStock(),
                ];
            }
            if (method_exists($extension, 'getWebsiteIds')) {
                $websiteIds = $extension->getWebsiteIds();
                if (is_array($websiteIds)) {
                    $detail['website_ids'] = array_map('intval', $websiteIds);
                }
            }
            if (method_exists($extension, 'getCategoryLinks')) {
                $links = $extension->getCategoryLinks();
                if (is_array($links)) {
                    $detail['category_ids'] = array_map(
                        static fn ($link): int => (int) $link->getCategoryId(),
                        $links
                    );
                }
            }
        }

        foreach ($extraAttributes as $code) {
            if (!is_string($code) || $code === '') {
                continue;
            }
            $attribute = $product->getCustomAttribute($code);
            $detail['custom_attributes'][$code] = $attribute?->getValue() ?? $product->getData($code);
        }

        return $detail;
    }
}
