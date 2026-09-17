<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\Quote\CartLocator;
use Magenx\AiMcp\Model\Tool\Quote\CartProjector;
use Magenx\AiMcp\Model\Tool\Quote\PlaceOrder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Committing a cart to an order.
 *
 * The most irreversible call this server can make: it commits a customer to a
 * purchase, reserves stock and on most stores e-mails them, all before it
 * returns. Nothing undoes that from here — cancel_order closes the order and
 * releases the stock, but the e-mail has gone and the order still exists.
 *
 * So the tests are almost entirely about what must happen BEFORE
 * `placeOrder()` is reached, and about the one thing that must be read before
 * it: a cart is deactivated by being placed, so the record of what was bought
 * has to be taken while it is still a cart.
 *
 * @see PlaceOrder::execute
 */
class PlaceOrderTest extends TestCase
{
    private CartLocator&MockObject $locator;
    private CartManagementInterface&MockObject $cartManagement;
    private PaymentMethodManagementInterface&MockObject $paymentMethodManagement;
    private PlaceOrder $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(CartLocator::class);
        $this->locator->method('schemaProperties')->willReturn(['cart_id' => ['type' => 'integer']]);

        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->paymentMethodManagement = $this->createMock(PaymentMethodManagementInterface::class);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $projector = $this->createMock(CartProjector::class);
        $projector->method('toDetail')->willReturn(['cart_id' => 7]);

        $this->tool = new PlaceOrder(
            $this->locator,
            $this->cartManagement,
            $this->paymentMethodManagement,
            $orderRepository,
            $projector
        );
    }

    /**
     * Deliberately not Magento_Cart::manage. Assembling a cart and committing
     * somebody to buy it are different permissions, so an integration can hold
     * one without the other.
     *
     * @return void
     */
    public function testItSitsBehindTheOrderCreationGrantNotTheCartOne(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Sales::create', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testItAdvertisesItselfAsDestructive(): void
    {
        $this->assertTrue($this->tool->getAnnotations()['destructiveHint']);
    }

    /**
     * @return void
     */
    public function testAnEmptyCartIsRefusedBeforeAnythingIsPlaced(): void
    {
        $this->locator->method('locateActive')->willReturn($this->cart([]));
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is empty, so there is nothing to order');

        $this->tool->execute(['cart_id' => 7]);
    }

    /**
     * Magento would refuse this too, but from inside the quote model and with a
     * message about a payment object rather than about the step that was
     * missed.
     *
     * @return void
     */
    public function testACartWithNoPaymentMethodIsRefusedNamingTheToolThatSetsOne(): void
    {
        $this->locator->method('locateActive')->willReturn($this->cart([$this->item()]));
        $this->paymentMethodManagement->method('get')->willReturn(null);
        $this->cartManagement->expects($this->never())->method('placeOrder');

        try {
            $this->tool->execute(['cart_id' => 7]);
            $this->fail('A cart with no payment method must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('set_cart_payment_method', $e->getMessage());
        }
    }

    /**
     * A payment object that exists but carries no method is the same problem
     * wearing a different shape, and must not slip past a null check.
     *
     * @return void
     */
    public function testAPaymentObjectWithNoMethodIsAlsoRefused(): void
    {
        $this->locator->method('locateActive')->willReturn($this->cart([$this->item()]));
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn('');
        $this->paymentMethodManagement->method('get')->willReturn($payment);
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['cart_id' => 7]);
    }

    /**
     * The payment lookup throwing must read as "no method", not as a crash —
     * Magento raises rather than returning null on some paths.
     *
     * @return void
     */
    public function testAThrowingPaymentLookupIsTreatedAsNoMethod(): void
    {
        $this->locator->method('locateActive')->willReturn($this->cart([$this->item()]));
        $this->paymentMethodManagement->method('get')->willThrowException(new \RuntimeException('none'));
        $this->cartManagement->expects($this->never())->method('placeOrder');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no payment method');

        $this->tool->execute(['cart_id' => 7]);
    }

    /**
     * @return void
     */
    public function testAReadyCartIsPlacedAndTheOrderIsNamedBothWays(): void
    {
        $this->readyCart();
        $this->cartManagement->expects($this->once())->method('placeOrder')->with(7)->willReturn(123);

        $result = $this->tool->execute(['cart_id' => 7]);

        $this->assertTrue($result['placed']);
        $this->assertSame(123, $result['order_id']);
        // The increment id is what the customer sees and quotes back; the
        // order_id is not.
        $this->assertSame('000000123', $result['increment_id']);
        $this->assertStringContainsString('cancel_order', $result['note']);
    }

    /**
     * Placing deactivates the quote, so the record of what was bought has to be
     * taken while it is still a cart. Reading it afterwards would report an
     * inactive shell.
     *
     * @return void
     */
    public function testTheCartIsProjectedBeforeItIsPlaced(): void
    {
        $this->readyCart();

        $order = [];
        $projector = $this->createMock(CartProjector::class);
        $projector->method('toDetail')->willReturnCallback(static function () use (&$order): array {
            $order[] = 'project';

            return [];
        });
        $this->cartManagement->method('placeOrder')->willReturnCallback(static function () use (&$order): int {
            $order[] = 'place';

            return 123;
        });

        $tool = new PlaceOrder(
            $this->locator,
            $this->cartManagement,
            $this->paymentMethodManagement,
            $this->createMock(OrderRepositoryInterface::class),
            $projector
        );
        $tool->execute(['cart_id' => 7]);

        $this->assertSame(['project', 'place'], $order);
    }

    /**
     * By the time the increment id is read the order exists. Failing to read it
     * must not fail the call, or an agent would retry a purchase that already
     * happened.
     *
     * @return void
     */
    public function testAFailureReadingTheOrderBackDoesNotFailThePlacedOrder(): void
    {
        $this->readyCart();
        $this->cartManagement->method('placeOrder')->willReturn(123);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willThrowException(new \RuntimeException('gone'));

        $projector = $this->createMock(CartProjector::class);
        $projector->method('toDetail')->willReturn([]);

        $tool = new PlaceOrder(
            $this->locator,
            $this->cartManagement,
            $this->paymentMethodManagement,
            $orderRepository,
            $projector
        );
        $result = $tool->execute(['cart_id' => 7]);

        $this->assertTrue($result['placed']);
        $this->assertSame(123, $result['order_id']);
        $this->assertNull($result['increment_id']);
    }

    /**
     * @return void
     */
    private function readyCart(): void
    {
        $this->locator->method('locateActive')->willReturn($this->cart([$this->item()]));
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn('checkmo');
        $this->paymentMethodManagement->method('get')->willReturn($payment);
    }

    /**
     * @param array<int, CartItemInterface> $items
     * @return CartInterface
     */
    private function cart(array $items): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(7);
        $cart->method('getItems')->willReturn($items);

        return $cart;
    }

    /**
     * @return CartItemInterface
     */
    private function item(): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-1');

        return $item;
    }
}
