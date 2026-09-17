<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\Quote\AddCartItem;
use Magenx\AiMcp\Model\Tool\Quote\CartLocator;
use Magenx\AiMcp\Model\Tool\Quote\CartProjector;
use Magenx\AiMcp\Model\Tool\Quote\CreateCart;
use Magenx\AiMcp\Model\Tool\Quote\RemoveCartItem;
use Magenx\AiMcp\Model\Tool\Quote\UpdateCartItem;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The cart tools' shared contract, and the three refusals not covered elsewhere.
 *
 * `place_order`, `set_cart_delivery`, the payment guard and the projector each
 * have their own file. What is left is the surface every tool here shares —
 * which grant it sits behind, whether it writes — plus the places where a cart
 * tool refuses something Magento would otherwise accept and quietly get wrong.
 */
class CartToolContractTest extends TestCase
{
    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        GeneratedFactory::ensure(CartItemInterfaceFactory::class);
    }

    /**
     * Every cart tool that writes sits behind Magento's own cart-management
     * grant. Only place_order differs, and its own file pins that.
     *
     * @return void
     */
    public function testTheCartWritesShareTheCartManageGrant(): void
    {
        foreach ([$this->createCart(), $this->addItem(), $this->updateItem(), $this->removeItem()] as $tool) {
            $this->assertTrue($tool->isWrite(), $tool->getName());
            $this->assertSame('Magento_Cart::manage', $tool->getAclResource(), $tool->getName());
            $this->assertFalse($tool->getInputSchema()['additionalProperties'], $tool->getName());
        }
    }

    /**
     * None of them removes anything or charges anything — a cart is not an
     * order until place_order.
     *
     * @return void
     */
    public function testBuildingACartIsNotDestructive(): void
    {
        foreach ([$this->createCart(), $this->addItem(), $this->updateItem()] as $tool) {
            $this->assertFalse($tool->getAnnotations()['destructiveHint'], $tool->getName());
        }
    }

    /**
     * The store decides currency, prices, tax and which methods exist, and
     * createEmptyCart() takes no store at all — so it cannot be optional.
     *
     * @return void
     */
    public function testCreateCartRequiresAStore(): void
    {
        $this->assertSame(['store_code'], $this->createCart()->getInputSchema()['required']);
    }

    /**
     * Scope 0 is not a storefront: no currency, no catalogue prices, no
     * shipping. A cart there would price everything at nothing, and
     * StoreResolver maps both null and "admin" to it.
     *
     * @return void
     */
    public function testACartCannotBeCreatedInTheAdminScope(): void
    {
        $storeResolver = $this->createMock(StoreResolver::class);
        $storeResolver->method('schemaProperty')->willReturn(['type' => 'string']);
        $storeResolver->method('resolve')->willReturn(0);

        $cartManagement = $this->createMock(CartManagementInterface::class);
        $cartManagement->expects($this->never())->method('createEmptyCart');

        $tool = new CreateCart(
            $cartManagement,
            $this->createMock(CartRepositoryInterface::class),
            $storeResolver,
            $this->createMock(CartProjector::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cannot be created in the admin scope');

        $tool->execute(['store_code' => 'admin']);
    }

    /**
     * Zero would save without error and add a line of nothing; the tool that
     * takes a line out is remove_cart_item, and the message says so.
     *
     * @return void
     */
    public function testAQuantityOfZeroIsRefusedByBothItemTools(): void
    {
        foreach ([[$this->addItem(), ['sku' => 'SKU-1']], [$this->updateItem(), ['item_id' => 3]]] as [$tool, $extra]) {
            try {
                $tool->execute(['cart_id' => 7, 'qty' => 0] + $extra);
                $this->fail(sprintf('%s must refuse a zero quantity.', $tool->getName()));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('remove_cart_item', $e->getMessage());
            }
        }
    }

    /**
     * Magento matches an item save on sku, so a wrong item_id would not fail —
     * it would add a second line, and the caller would see a success for an
     * edit that quietly became an addition.
     *
     * @return void
     */
    public function testUpdatingALineThatIsNotInTheCartIsRefused(): void
    {
        $repository = $this->createMock(CartItemRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $tool = new UpdateCartItem(
            $this->locatorWith([$this->item(3)]),
            $this->createMock(CartItemInterfaceFactory::class),
            $repository,
            $this->createMock(CartProjector::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no line with item_id 99');

        $tool->execute(['cart_id' => 7, 'item_id' => 99, 'qty' => 2]);
    }

    /**
     * @return void
     */
    public function testRemovingALineThatIsNotInTheCartIsRefused(): void
    {
        $repository = $this->createMock(CartItemRepositoryInterface::class);
        $repository->expects($this->never())->method('deleteById');

        $tool = new RemoveCartItem(
            $this->locatorWith([$this->item(3)]),
            $repository,
            $this->createMock(CartProjector::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no line with item_id 99');

        $tool->execute(['cart_id' => 7, 'item_id' => 99]);
    }

    /**
     * An agent naming its own price is a discount with no rule behind it and
     * nothing to reconcile the order against. CartItemInterface has a settable
     * price; neither tool offers it.
     *
     * @return void
     */
    public function testNeitherItemToolLetsTheCallerSetAPrice(): void
    {
        foreach ([$this->addItem(), $this->updateItem()] as $tool) {
            $this->assertArrayNotHasKey('price', $tool->getInputSchema()['properties'], $tool->getName());
        }
    }

    /**
     * @return CreateCart
     */
    private function createCart(): CreateCart
    {
        $storeResolver = $this->createMock(StoreResolver::class);
        $storeResolver->method('schemaProperty')->willReturn(['type' => 'string']);

        return new CreateCart(
            $this->createMock(CartManagementInterface::class),
            $this->createMock(CartRepositoryInterface::class),
            $storeResolver,
            $this->createMock(CartProjector::class)
        );
    }

    /**
     * @return AddCartItem
     */
    private function addItem(): AddCartItem
    {
        return new AddCartItem(
            $this->locatorWith([]),
            $this->createMock(CartItemInterfaceFactory::class),
            $this->createMock(CartItemRepositoryInterface::class),
            $this->createMock(CartProjector::class)
        );
    }

    /**
     * @return UpdateCartItem
     */
    private function updateItem(): UpdateCartItem
    {
        return new UpdateCartItem(
            $this->locatorWith([$this->item(3)]),
            $this->createMock(CartItemInterfaceFactory::class),
            $this->createMock(CartItemRepositoryInterface::class),
            $this->createMock(CartProjector::class)
        );
    }

    /**
     * @return RemoveCartItem
     */
    private function removeItem(): RemoveCartItem
    {
        return new RemoveCartItem(
            $this->locatorWith([$this->item(3)]),
            $this->createMock(CartItemRepositoryInterface::class),
            $this->createMock(CartProjector::class)
        );
    }

    /**
     * @param array<int, CartItemInterface> $items
     * @return CartLocator&MockObject
     */
    private function locatorWith(array $items): CartLocator&MockObject
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(7);
        $cart->method('getItems')->willReturn($items);

        $locator = $this->createMock(CartLocator::class);
        $locator->method('locateActive')->willReturn($cart);
        $locator->method('locate')->willReturn($cart);
        $locator->method('schemaProperties')->willReturn(['cart_id' => ['type' => 'integer']]);

        return $locator;
    }

    /**
     * @param int $itemId
     * @return CartItemInterface
     */
    private function item(int $itemId): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getSku')->willReturn('SKU-1');

        return $item;
    }
}
