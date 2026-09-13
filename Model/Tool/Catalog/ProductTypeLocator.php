<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Loads a product and insists it is the type the tool can actually work on.
 *
 * The bundle and downloadable services accept any sku and then fail somewhere
 * inside the type model — "Cannot find product" from the wrong layer, or a
 * silent no-op. A bundle option on a simple product and a downloadable link on
 * a configurable are both mistakes an agent makes from a product list that does
 * not put the type in front of it, so the type is checked once, here, and the
 * error says what the product actually is.
 */
class ProductTypeLocator
{
    /**
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @param string $sku
     * @param string $expectedType Magento type id, e.g. "bundle" or "downloadable".
     * @return ProductInterface
     * @throws LocalizedException
     */
    public function locate(string $sku, string $expectedType): ProductInterface
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $actual = (string) $product->getTypeId();
        if ($actual !== $expectedType) {
            throw new LocalizedException(__(
                'Product "%1" is a %2 product, not %3. This tool only works on %3 products.',
                $sku,
                $actual,
                $expectedType
            ));
        }

        return $product;
    }
}
