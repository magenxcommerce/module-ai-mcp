<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\Quote\CartProjector;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\TotalsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Presenting a cart.
 *
 * Two things here are not stylistic choices.
 *
 * The summary carries no money because `CartInterface` carries none — totals
 * are a separate service call per cart, which a page of them cannot afford.
 * Somebody adding a grand total to the summary would make search_carts issue
 * one service call per row.
 *
 * And a cart that has been placed is still a cart in the database. `is_active`
 * false with a `reserved_order_id` means bought; `is_active` true with a stale
 * `updated_at` means abandoned. Reading those the wrong way round turns "we
 * lost these sales" into "we made them".
 *
 * @see CartProjector
 */
class CartProjectorTest extends TestCase
{
    private CartTotalRepositoryInterface&MockObject $cartTotalRepository;
    private CartProjector $projector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->cartTotalRepository = $this->createMock(CartTotalRepositoryInterface::class);
        $this->projector = new CartProjector($this->cartTotalRepository);
    }

    /**
     * @return void
     */
    public function testTheSummaryCarriesNoMoneyAndMakesNoTotalsCall(): void
    {
        $this->cartTotalRepository->expects($this->never())->method('get');

        $summary = $this->projector->toSummary($this->cart());

        foreach (['totals', 'grand_total', 'subtotal'] as $key) {
            $this->assertArrayNotHasKey($key, $summary, $key);
        }
    }

    /**
     * @return void
     */
    public function testTheSummaryDistinguishesBoughtFromAbandoned(): void
    {
        $placed = $this->projector->toSummary($this->cart([
            'is_active' => false,
            'reserved_order_id' => '000000123',
            'converted_at' => '2026-09-16 10:00:00',
        ]));

        $this->assertFalse($placed['is_active']);
        $this->assertSame('000000123', $placed['reserved_order_id']);

        $abandoned = $this->projector->toSummary($this->cart(['is_active' => true]));

        $this->assertTrue($abandoned['is_active']);
        $this->assertNull($abandoned['reserved_order_id']);
    }

    /**
     * @return void
     */
    public function testTheDetailAddsItemsAndTotals(): void
    {
        $this->cartTotalRepository->method('get')->willReturn($this->totals());

        $detail = $this->projector->toDetail($this->cart([], [$this->item()]));

        $this->assertSame(99.5, $detail['totals']['grand_total']);
        $this->assertSame('GBP', $detail['totals']['currency']);
        $this->assertSame([[
            'item_id' => 3,
            'sku' => 'SKU-1',
            'name' => 'A thing',
            'qty' => 2.0,
            'price' => 25.0,
            'product_type' => 'simple',
        ]], $detail['items']);
    }

    /**
     * A cart whose totals cannot be read is not a cart worth nothing, and the
     * two must not look alike to a caller deciding whether to place the order.
     *
     * @return void
     */
    public function testUnreadableTotalsAreNullRatherThanZero(): void
    {
        $this->cartTotalRepository->method('get')->willThrowException(new \RuntimeException('no'));

        $detail = $this->projector->toDetail($this->cart());

        $this->assertNull($detail['totals']);
    }

    /**
     * @return void
     */
    public function testAGuestCartReportsNoCustomer(): void
    {
        $summary = $this->projector->toSummary($this->cart(['customer_is_guest' => true]));

        $this->assertNull($summary['customer_id']);
        $this->assertNull($summary['customer_email']);
        $this->assertTrue($summary['customer_is_guest']);
    }

    /**
     * @return void
     */
    public function testACustomerCartReportsWhoItBelongsTo(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('ada@example.com');

        $summary = $this->projector->toSummary($this->cart(['customer' => $customer]));

        $this->assertSame(42, $summary['customer_id']);
        $this->assertSame('ada@example.com', $summary['customer_email']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<int, CartItemInterface> $items
     * @return CartInterface
     */
    private function cart(array $overrides = [], array $items = []): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(7);
        $cart->method('getStoreId')->willReturn(1);
        $cart->method('getIsActive')->willReturn($overrides['is_active'] ?? true);
        $cart->method('getIsVirtual')->willReturn(false);
        $cart->method('getItems')->willReturn($items);
        $cart->method('getItemsCount')->willReturn(count($items));
        $cart->method('getItemsQty')->willReturn(2);
        $cart->method('getCustomer')->willReturn($overrides['customer'] ?? null);
        $cart->method('getCustomerIsGuest')->willReturn($overrides['customer_is_guest'] ?? false);
        $cart->method('getCurrency')->willReturn(null);
        $cart->method('getReservedOrderId')->willReturn($overrides['reserved_order_id'] ?? null);
        $cart->method('getConvertedAt')->willReturn($overrides['converted_at'] ?? null);

        return $cart;
    }

    /**
     * @return CartItemInterface
     */
    private function item(): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn(3);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getName')->willReturn('A thing');
        $item->method('getQty')->willReturn(2);
        $item->method('getPrice')->willReturn(25.0);
        $item->method('getProductType')->willReturn('simple');

        return $item;
    }

    /**
     * @return TotalsInterface
     */
    private function totals(): TotalsInterface
    {
        $totals = $this->createMock(TotalsInterface::class);
        $totals->method('getGrandTotal')->willReturn(99.5);
        $totals->method('getQuoteCurrencyCode')->willReturn('GBP');

        return $totals;
    }
}
