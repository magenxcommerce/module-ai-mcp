<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\QuickSearchGraphQl\Model\Source\Type;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Whether a promotion's target exists at all, and what it is called.
 *
 * Stricter than the admin form on purpose. The admin checks only that a
 * product SKU exists and that a category or brand target is numeric, so a
 * category id that was never there, or a brand option deleted last year, saves
 * without complaint — and the storefront then drops the promotion silently.
 * In the admin a person is looking at the grid; through this server nobody is,
 * so a target that does not exist is refused here.
 *
 * Existence is all this checks. A disabled or out-of-stock product is a
 * legitimate target to schedule ahead of a launch, so whether the storefront
 * would show it today is reported by {@see StorefrontVisibility} rather than
 * refused.
 */
class TargetValidator
{
    /** The attribute brand promotions point at, as in the quick search module. */
    private const BRAND_ATTRIBUTE = 'manufacturer';

    /**
     * @param ProductResource $productResource
     * @param CategoryRepositoryInterface $categoryRepository
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Refuse a target that does not exist; return its admin label when it does.
     *
     * @param string $type
     * @param string $target
     * @return string
     * @throws LocalizedException
     */
    public function assertExists(string $type, string $target): string
    {
        $label = $this->label($type, $target);
        if ($label !== null) {
            return $label;
        }

        throw new LocalizedException(match ($type) {
            Type::PRODUCT => __(
                'No product has the SKU "%1". Use search_products to find the SKU.',
                $target
            ),
            Type::CATEGORY => __(
                'No category exists with id %1. Use get_category_tree to find the id.',
                $target
            ),
            default => __(
                'No "%1" attribute option exists with id %2. Use get_product_attribute with '
                . 'attribute_code "%1" to list the brand options and their ids.',
                self::BRAND_ATTRIBUTE,
                $target
            ),
        });
    }

    /**
     * The target's admin label, or null when it does not exist.
     *
     * The label is a SKU's product name, a category's name or a brand option's
     * admin label — what a person would recognise the target by.
     *
     * @param string $type
     * @param string $target
     * @return string|null
     */
    public function label(string $type, string $target): ?string
    {
        return match ($type) {
            Type::PRODUCT => $this->productLabel($target),
            Type::CATEGORY => $this->categoryLabel($target),
            Type::BRAND => $this->brandLabel($target),
            default => null,
        };
    }

    /**
     * @param string $sku
     * @return string|null
     */
    private function productLabel(string $sku): ?string
    {
        $productId = $this->productResource->getIdBySku($sku);
        if (!$productId) {
            return null;
        }

        $name = $this->productResource->getAttributeRawValue((int) $productId, 'name', 0);

        return is_string($name) && $name !== '' ? $name : $sku;
    }

    /**
     * @param string $categoryId
     * @return string|null
     */
    private function categoryLabel(string $categoryId): ?string
    {
        if (!ctype_digit($categoryId)) {
            return null;
        }

        try {
            return (string) $this->categoryRepository->get((int) $categoryId)->getName();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @param string $optionId
     * @return string|null
     */
    private function brandLabel(string $optionId): ?string
    {
        if (!ctype_digit($optionId)) {
            return null;
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::BRAND_ATTRIBUTE);
        if (!$attribute || !$attribute->getId() || !$attribute->usesSource()) {
            return null;
        }

        $label = $attribute->getSource()->getOptionText($optionId);

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }
}
