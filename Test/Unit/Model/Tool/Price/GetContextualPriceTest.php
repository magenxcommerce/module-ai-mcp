<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Price;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Price\ContextualPriceReader;
use Magenx\AiMcp\Model\Tool\Price\GetContextualPrice;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The price a customer group pays, as the storefront computes it.
 *
 * @see GetContextualPrice
 */
class GetContextualPriceTest extends TestCase
{
    /**
     * A percentage tier is off the regular price, and the unit price is the
     * lowest of the final price and the tier that applies.
     *
     * @return void
     */
    public function testTheUnitPriceIsTheLowerOfFinalAndTier(): void
    {
        $reader = $this->createMock(ContextualPriceReader::class);
        $reader->method('indexedPrice')->with(42, 2, 1)->willReturn([
            'price' => '100.0000',
            'final_price' => '90.0000',
            'min_price' => '90.0000',
            'max_price' => '90.0000',
            'tier_price' => '80.0000',
        ]);
        $reader->method('rulePrice')->with(42, 2, 1, '2026-03-15')->willReturn(90.0);
        $reader->method('tierPrices')->with(42, 2, 1, 10.0)->willReturn([
            ['qty' => '5', 'value' => '88', 'percentage_value' => null],
            ['qty' => '10', 'value' => '0', 'percentage_value' => '15'],
        ]);

        $result = $this->tool($reader)->execute([
            'sku' => 'MUG',
            'customer_group_id' => 1,
            'store_code' => 'en',
            'qty' => 10,
        ]);

        $this->assertSame(85.0, $result['tier_price_for_qty']);
        $this->assertSame(85.0, $result['unit_price']);
        $this->assertSame(90.0, $result['catalog_rule_price']);
        $this->assertSame(2, $result['website_id']);
        $this->assertFalse($result['price_index_status']['valid']);
        $this->assertNull($result['note']);
    }

    /**
     * No index row is said plainly rather than reported as a zero price.
     *
     * @return void
     */
    public function testAMissingIndexRowIsExplainedNotPricedAtZero(): void
    {
        $reader = $this->createMock(ContextualPriceReader::class);
        $reader->method('indexedPrice')->willReturn(null);
        $reader->method('tierPrices')->willReturn([]);

        $result = $this->tool($reader)->execute(['sku' => 'MUG', 'customer_group_id' => 0, 'store_code' => 'en']);

        $this->assertNull($result['price_index']);
        $this->assertNull($result['unit_price']);
        $this->assertStringContainsString('no row', $result['note']);
    }

    /**
     * @return void
     */
    public function testAnUnknownSkuIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->tool($this->createMock(ContextualPriceReader::class), productId: false)
            ->execute(['sku' => 'nope', 'customer_group_id' => 0, 'store_code' => 'en']);
    }

    /**
     * @param ContextualPriceReader $reader
     * @param int|false $productId
     * @return GetContextualPrice
     */
    private function tool(ContextualPriceReader $reader, int|false $productId = 42): GetContextualPrice
    {
        $products = $this->createMock(ProductResource::class);
        $products->method('getIdBySku')->willReturn($productId);

        $stores = $this->createMock(StoreResolver::class);
        $stores->method('resolve')->willReturn(3);

        $store = new class {
            /**
             * @return int
             */
            public function getWebsiteId(): int
            {
                return 2;
            }
        };
        $repository = $this->createMock(StoreRepositoryInterface::class);
        $repository->method('getById')->with(3)->willReturn($store);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('scopeDate')->willReturn(new \DateTime('2026-03-15 08:00:00'));

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isValid')->willReturn(false);
        $indexer->method('getStatus')->willReturn('invalid');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->willReturn($indexer);

        return new GetContextualPrice($products, $stores, $repository, $timezone, $registry, $reader);
    }
}
