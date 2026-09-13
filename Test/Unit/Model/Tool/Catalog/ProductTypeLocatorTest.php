<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The type check in front of the bundle and downloadable tools.
 *
 * Both sets of services accept any sku and then fail somewhere inside the type
 * model, or quietly do nothing. A product list does not put the type in front
 * of an agent, so the mistake is easy and the error has to say what the product
 * actually is.
 *
 * @see ProductTypeLocator
 */
class ProductTypeLocatorTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $productRepository;
    private ProductTypeLocator $locator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->locator = new ProductTypeLocator($this->productRepository);
    }

    /**
     * @return void
     */
    public function testTheRightTypeIsReturned(): void
    {
        $product = $this->product('bundle');
        $this->productRepository->method('get')->with('SKU-1')->willReturn($product);

        $this->assertSame($product, $this->locator->locate('SKU-1', 'bundle'));
    }

    /**
     * @return void
     */
    public function testAWrongTypeSaysWhatTheProductIs(): void
    {
        $this->productRepository->method('get')->willReturn($this->product('simple'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Product "SKU-1" is a simple product, not bundle. This tool only works on bundle products.'
        );
        $this->locator->locate('SKU-1', 'bundle');
    }

    /**
     * @return void
     */
    public function testAnUnknownSkuIsNamed(): void
    {
        $this->productRepository->method('get')->willThrowException(new NoSuchEntityException(__('nope')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No product exists with sku "SKU-1".');
        $this->locator->locate('SKU-1', 'downloadable');
    }

    /**
     * @param string $typeId
     * @return ProductInterface&MockObject
     */
    private function product(string $typeId): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getTypeId')->willReturn($typeId);

        return $product;
    }
}
