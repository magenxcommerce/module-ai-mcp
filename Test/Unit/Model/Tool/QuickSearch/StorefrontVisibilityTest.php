<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\QuickSearch\StorefrontVisibility;
use Magenx\QuickSearchGraphQl\Model\Config;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\PromotionProvider;
use Magenx\QuickSearchGraphQl\Model\TargetLoader;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Whether the dropdown would show a promotion, asked of the quick search
 * module's own code in the order the resolver applies its rules.
 *
 * The order matters as much as the rules: the Maximum Items cap is applied to
 * the pool before targets are resolved, so a promotion past the cap is reported
 * as cut by the cap even when its target is fine.
 */
class StorefrontVisibilityTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private Config&MockObject $config;
    private PromotionProvider&MockObject $provider;
    private TargetLoader&MockObject $targetLoader;
    private StorefrontVisibility $visibility;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->provider = $this->createMock(PromotionProvider::class);
        $this->targetLoader = $this->createMock(TargetLoader::class);

        $this->config->method('isPromotionsEnabled')->willReturn(true);
        $this->config->method('getPromotionsMaxItems')->willReturn(2);

        $this->visibility = new StorefrontVisibility(
            $this->storeManager,
            $this->config,
            $this->provider,
            $this->targetLoader
        );
    }

    /**
     * @return void
     */
    public function testALivePromotionWithAResolvingTargetIsShown(): void
    {
        $this->storeManager->method('getStore')->with(1)->willReturn($this->store(1, 'default'));
        $this->provider->method('getActive')->willReturn([['promotion_id' => '7']]);
        $this->targetLoader->method('loadProducts')->with(['MB01'], 1)->willReturn(['mb01' => true]);

        $this->assertSame(
            [['store_id' => 1, 'store_code' => 'default', 'shown' => true, 'reason' => null]],
            $this->visibility->check($this->promotion(['store_id' => 1]))
        );
    }

    /**
     * @return void
     */
    public function testPromotionsSwitchedOffForTheStoreAreReportedFirst(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPromotionsEnabled')->willReturn(false);
        $visibility = new StorefrontVisibility($this->storeManager, $config, $this->provider, $this->targetLoader);
        $this->storeManager->method('getStore')->willReturn($this->store(1, 'default'));

        $this->provider->expects($this->never())->method('getActive');

        $result = $visibility->check($this->promotion(['store_id' => 1]));

        $this->assertFalse($result[0]['shown']);
        $this->assertStringContainsString('magenx_quick_search/promotions/enabled', $result[0]['reason']);
    }

    /**
     * @return void
     */
    public function testAPromotionMissingFromThePoolIsOutsideItsDates(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1, 'default'));
        $this->provider->method('getActive')->willReturn([['promotion_id' => '3']]);

        $result = $this->visibility->check($this->promotion(['store_id' => 1]));

        $this->assertStringContainsString('outside the promotion\'s', $result[0]['reason']);
    }

    /**
     * @return void
     */
    public function testAPromotionPastTheCapIsCutEvenWhenItsTargetWouldResolve(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1, 'default'));
        $this->provider->method('getActive')->willReturn([
            ['promotion_id' => '1'],
            ['promotion_id' => '2'],
            ['promotion_id' => '7'],
        ]);
        $this->targetLoader->expects($this->never())->method('loadProducts');

        $result = $this->visibility->check($this->promotion(['store_id' => 1]));

        $this->assertStringContainsString('number 3', $result[0]['reason']);
        $this->assertStringContainsString('cap of 2', $result[0]['reason']);
    }

    /**
     * @return void
     */
    public function testATargetTheStorefrontDropsIsReported(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1, 'default'));
        $this->provider->method('getActive')->willReturn([['promotion_id' => '7']]);
        $this->targetLoader->method('loadProducts')->willReturn([]);

        $result = $this->visibility->check($this->promotion(['store_id' => 1]));

        $this->assertStringContainsString('out of stock', $result[0]['reason']);
    }

    /**
     * A category is resolved under the root of the store view being checked.
     *
     * @return void
     */
    public function testACategoryIsLookedUpUnderTheStoresRoot(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1, 'default', 2));
        $this->provider->method('getActive')->willReturn([['promotion_id' => '7']]);
        $this->targetLoader->expects($this->once())
            ->method('loadCategories')
            ->with(['24'], 1, 2)
            ->willReturn([24 => []]);

        $result = $this->visibility->check(
            $this->promotion(['store_id' => 1, 'type' => 'category', 'target' => '24'])
        );

        $this->assertTrue($result[0]['shown']);
    }

    /**
     * Each store view has its own config, date and catalogue.
     *
     * @return void
     */
    public function testAPromotionForEveryStoreViewIsCheckedInEach(): void
    {
        $this->storeManager->method('getStores')
            ->willReturn([1 => $this->store(1, 'en'), 2 => $this->store(2, 'de')]);
        $this->provider->method('getActive')->willReturn([['promotion_id' => '7']]);
        $this->targetLoader->method('loadProducts')
            ->willReturnCallback(static fn (array $skus, int $storeId): array => $storeId === 1 ? ['mb01' => true] : []);

        $result = $this->visibility->check($this->promotion(['store_id' => 0]));

        $this->assertSame(['en', 'de'], array_column($result, 'store_code'));
        $this->assertSame([true, false], array_column($result, 'shown'));
    }

    /**
     * @param array<string, mixed> $data
     * @return Promotion
     */
    private function promotion(array $data): Promotion
    {
        $promotion = new Promotion();
        foreach ($data + ['promotion_id' => 7, 'type' => 'product', 'target' => 'MB01', 'is_active' => 1] as $k => $v) {
            $promotion->setData($k, $v);
        }

        return $promotion;
    }

    /**
     * @param int $id
     * @param string $code
     * @param int $rootCategoryId
     * @return Store
     */
    private function store(int $id, string $code, int $rootCategoryId = 2): Store
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);

        return $store;
    }
}
