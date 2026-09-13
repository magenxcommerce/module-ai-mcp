<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\Bundle\BundleLinkArguments;
use Magenx\AiMcp\Model\Tool\Bundle\BundleOptionProjector;
use Magenx\AiMcp\Model\Tool\Bundle\SaveBundleOption;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterface;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Api\ProductOptionManagementInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Saving a bundle option, and what that does to the selections already on it.
 *
 * Magento replaces an option's whole selection set when it is handed one, but
 * "save this option with these products" reads like adding them. Getting that
 * backwards silently removes selections from a live bundle, which is the same
 * trap set_product_links documents — so the mode is explicit and the default
 * matches Magento.
 *
 * @see SaveBundleOption
 */
class SaveBundleOptionTest extends TestCase
{
    private ProductOptionManagementInterface&MockObject $optionManagement;
    private ProductOptionRepositoryInterface&MockObject $optionRepository;
    private SaveBundleOption $tool;

    /** @var array<int, LinkInterface>|null */
    private ?array $savedLinks = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        GeneratedFactory::ensure(OptionInterfaceFactory::class);
        GeneratedFactory::ensure(LinkInterfaceFactory::class);

        $this->optionManagement = $this->createMock(ProductOptionManagementInterface::class);
        $this->optionRepository = $this->createMock(ProductOptionRepositoryInterface::class);

        $option = $this->createMock(OptionInterface::class);
        $option->method('setProductLinks')->willReturnCallback(
            function (?array $links): void {
                $this->savedLinks = $links;
            }
        );
        $optionFactory = $this->createMock(OptionInterfaceFactory::class);
        $optionFactory->method('create')->willReturn($option);

        $linkFactory = $this->createMock(LinkInterfaceFactory::class);
        $linkFactory->method('create')->willReturnCallback(
            fn (): LinkInterface => $this->link(null)
        );

        $productLocator = $this->createMock(ProductTypeLocator::class);
        $this->optionManagement->method('save')->willReturn(4);
        $this->optionRepository->method('get')->willReturn($this->createMock(OptionInterface::class));

        $this->tool = new SaveBundleOption(
            $this->optionManagement,
            $this->optionRepository,
            $optionFactory,
            $productLocator,
            new BundleLinkArguments($linkFactory),
            new BundleOptionProjector()
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheProductGrant(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Catalog::products', $this->tool->getAclResource());
    }

    /**
     * Magento's own behaviour, and the default: what is listed is what the
     * option ends up offering.
     *
     * @return void
     */
    public function testReplaceIsTheDefaultAndDropsWhatIsNotListed(): void
    {
        $this->existingOptionOffers('A', 'B');

        $this->tool->execute([
            'sku' => 'BUNDLE-1',
            'option_id' => 4,
            'product_links' => [['sku' => 'C']],
        ]);

        $this->assertSame(['C'], $this->savedSkus());
    }

    /**
     * @return void
     */
    public function testAppendCarriesTheExistingSelectionsThrough(): void
    {
        $this->existingOptionOffers('A', 'B');

        $this->tool->execute([
            'sku' => 'BUNDLE-1',
            'option_id' => 4,
            'product_links' => [['sku' => 'C']],
            'mode' => 'append',
        ]);

        $this->assertSame(['A', 'B', 'C'], $this->savedSkus());
    }

    /**
     * Omitting the argument entirely is the way to change a title without
     * touching what the option offers.
     *
     * @return void
     */
    public function testOmittingProductLinksLeavesTheSelectionsAlone(): void
    {
        $this->existingOptionOffers('A', 'B');

        $this->tool->execute([
            'sku' => 'BUNDLE-1',
            'option_id' => 4,
            'title' => 'Pick a strap',
        ]);

        $this->assertNull($this->savedLinks);
    }

    /**
     * @return void
     */
    public function testAnEmptyListIsRefusedRatherThanLeavingAnUnbuyableOption(): void
    {
        $this->existingOptionOffers('A');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('An option with no selections cannot be bought.');
        $this->tool->execute([
            'sku' => 'BUNDLE-1',
            'option_id' => 4,
            'product_links' => [],
        ]);
    }

    /**
     * @return void
     */
    public function testAnOptionIdFromAnotherBundleIsRefused(): void
    {
        $this->existingOptionOffers('A');
        $this->optionManagement->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Bundle "BUNDLE-1" has no option with option_id 99.');
        $this->tool->execute(['sku' => 'BUNDLE-1', 'option_id' => 99, 'title' => 'Nope']);
    }

    /**
     * @return void
     */
    public function testCreatingNeedsATitleAndAType(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "title" argument is required when creating an option.');
        $this->tool->execute(['sku' => 'BUNDLE-1', 'product_links' => [['sku' => 'A']]]);
    }

    /**
     * One option, id 4, already offering the named skus.
     *
     * @param string ...$skus
     * @return void
     */
    private function existingOptionOffers(string ...$skus): void
    {
        $existing = $this->createMock(OptionInterface::class);
        $existing->method('getOptionId')->willReturn(4);
        $existing->method('getTitle')->willReturn('Strap');
        $existing->method('getType')->willReturn('radio');
        $existing->method('getRequired')->willReturn(true);
        $existing->method('getPosition')->willReturn(1);
        $existing->method('getProductLinks')->willReturn(
            array_map(fn (string $sku): LinkInterface => $this->link($sku), $skus)
        );

        $this->optionRepository->method('getList')->willReturn([$existing]);
    }

    /**
     * @return array<int, string|null>
     */
    private function savedSkus(): array
    {
        return array_map(
            static fn (LinkInterface $link): ?string => $link->getSku(),
            $this->savedLinks ?? []
        );
    }

    /**
     * A link that remembers the sku set on it, so the saved list can be read back.
     *
     * @param string|null $sku
     * @return LinkInterface&MockObject
     */
    private function link(?string $sku): LinkInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('setSku')->willReturnCallback(static function (string $value) use (&$sku): void {
            $sku = $value;
        });
        // By reference on both sides: an arrow function would capture the sku
        // as it was when the link was created, before setSku ever ran.
        $link->method('getSku')->willReturnCallback(static function () use (&$sku): ?string {
            return $sku;
        });

        return $link;
    }
}
