<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\Quote\CartAddressArguments;
use Magenx\AiMcp\Model\Tool\Quote\CartLocator;
use Magenx\AiMcp\Model\Tool\Quote\CartProjector;
use Magenx\AiMcp\Model\Tool\Quote\SetCartDelivery;
use Magento\Checkout\Api\Data\PaymentDetailsInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\BillingAddressManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Setting where an order goes and how it gets there.
 *
 * The branch is the point. A cart of only virtual or downloadable products
 * ships nothing, has no shipping address and no carrier, and Magento's
 * shipping-information service fails on one from somewhere inside rate
 * estimation. Asking the cart which it is first turns that into "this cart
 * needs no shipping method", which is a sentence an agent can act on.
 *
 * @see SetCartDelivery::execute
 */
class SetCartDeliveryTest extends TestCase
{
    private CartLocator&MockObject $locator;
    private ShippingInformationManagementInterface&MockObject $shippingInformationManagement;
    private BillingAddressManagementInterface&MockObject $billingAddressManagement;
    private SetCartDelivery $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(CartLocator::class);
        $this->locator->method('schemaProperties')->willReturn(['cart_id' => ['type' => 'integer']]);

        $addressFactory = $this->createMock(AddressInterfaceFactory::class);
        $addressFactory->method('create')->willReturnCallback(
            fn (): AddressInterface => $this->createMock(AddressInterface::class)
        );

        $information = $this->createMock(ShippingInformationInterface::class);
        $informationFactory = $this->createMock(ShippingInformationInterfaceFactory::class);
        $informationFactory->method('create')->willReturn($information);

        $this->shippingInformationManagement = $this->createMock(ShippingInformationManagementInterface::class);
        $this->billingAddressManagement = $this->createMock(BillingAddressManagementInterface::class);

        $projector = $this->createMock(CartProjector::class);
        $projector->method('toDetail')->willReturn([]);

        $this->tool = new SetCartDelivery(
            $this->locator,
            new CartAddressArguments($addressFactory),
            $informationFactory,
            $this->shippingInformationManagement,
            $this->billingAddressManagement,
            $projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheCartManageGrant(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Cart::manage', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAPhysicalCartSetsAddressesAndMethodInOneCall(): void
    {
        $this->cartIs(virtual: false);
        $this->shippingInformationManagement->expects($this->once())
            ->method('saveAddressInformation')
            ->willReturn($this->paymentDetails(['checkmo' => 'Check', 'braintree' => 'Card']));
        $this->billingAddressManagement->expects($this->never())->method('assign');

        $result = $this->tool->execute($this->physicalArguments());

        $this->assertTrue($result['applied']);
        $this->assertFalse($result['is_virtual']);
        $this->assertSame('flatrate', $result['carrier_code']);
        // The call hands back what the cart can be paid with, so the next step
        // is answered without a second round trip.
        $this->assertSame(
            [
                ['code' => 'checkmo', 'title' => 'Check', 'usable_here' => true],
                ['code' => 'braintree', 'title' => 'Card', 'usable_here' => false],
            ],
            $result['payment_methods_available']
        );
    }

    /**
     * @return void
     */
    public function testAPhysicalCartWithoutAShippingMethodIsRefused(): void
    {
        $this->cartIs(virtual: false);
        $this->shippingInformationManagement->expects($this->never())->method('saveAddressInformation');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"carrier_code" argument is required');

        $this->tool->execute(['cart_id' => 7, 'billing_address' => ['firstname' => 'Ada']]);
    }

    /**
     * The branch: a virtual cart never reaches the shipping service.
     *
     * @return void
     */
    public function testAVirtualCartTakesTheBillingOnlyPath(): void
    {
        $this->cartIs(virtual: true);
        $this->shippingInformationManagement->expects($this->never())->method('saveAddressInformation');
        $this->billingAddressManagement->expects($this->once())->method('assign');

        $result = $this->tool->execute(['cart_id' => 7, 'billing_address' => ['firstname' => 'Ada']]);

        $this->assertTrue($result['applied']);
        $this->assertTrue($result['is_virtual']);
        $this->assertNull($result['carrier_code']);
        $this->assertStringContainsString('ships nothing', $result['next_step']);
    }

    /**
     * Passing shipping details for a cart that ships nothing is a
     * misunderstanding worth naming, not something to accept and ignore —
     * accepting it would leave the caller believing a carrier had been chosen.
     *
     * @return void
     */
    public function testAVirtualCartRefusesShippingArgumentsRatherThanIgnoringThem(): void
    {
        $this->cartIs(virtual: true);
        $this->billingAddressManagement->expects($this->never())->method('assign');

        $shippingOnly = [
            'shipping_address' => ['city' => 'X'],
            'carrier_code' => 'flatrate',
            'method_code' => 'flatrate',
        ];

        foreach ($shippingOnly as $key => $value) {
            try {
                $this->tool->execute([
                    'cart_id' => 7,
                    'billing_address' => ['firstname' => 'Ada'],
                    $key => $value,
                ]);
                $this->fail(sprintf('A virtual cart must refuse "%s".', $key));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('ships nothing', $e->getMessage());
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /**
     * Omitting the shipping address ships to the billing address, which is the
     * common case and should not need saying twice.
     *
     * @return void
     */
    public function testOmittingTheShippingAddressShipsToTheBillingAddress(): void
    {
        $this->cartIs(virtual: false);
        $this->shippingInformationManagement->expects($this->once())
            ->method('saveAddressInformation')
            ->willReturn($this->paymentDetails([]));

        $arguments = $this->physicalArguments();
        unset($arguments['shipping_address']);

        $this->assertTrue($this->tool->execute($arguments)['applied']);
    }

    /**
     * @return void
     */
    public function testABillingAddressIsRequired(): void
    {
        $this->cartIs(virtual: false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"billing_address" argument is required');

        $this->tool->execute(['cart_id' => 7, 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']);
    }

    /**
     * @param bool $virtual
     * @return void
     */
    private function cartIs(bool $virtual): void
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(7);
        $cart->method('getIsVirtual')->willReturn($virtual);

        $this->locator->method('locateActive')->willReturn($cart);
        $this->locator->method('locate')->willReturn($cart);
    }

    /**
     * @param array<string, string> $codes
     * @return PaymentDetailsInterface
     */
    private function paymentDetails(array $codes): PaymentDetailsInterface
    {
        $methods = [];
        foreach ($codes as $code => $title) {
            $method = $this->createMock(PaymentMethodInterface::class);
            $method->method('getCode')->willReturn($code);
            $method->method('getTitle')->willReturn($title);
            $methods[] = $method;
        }

        $details = $this->createMock(PaymentDetailsInterface::class);
        $details->method('getPaymentMethods')->willReturn($methods);

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function physicalArguments(): array
    {
        return [
            'cart_id' => 7,
            'billing_address' => ['firstname' => 'Ada', 'city' => 'London'],
            'shipping_address' => ['firstname' => 'Ada', 'city' => 'London'],
            'carrier_code' => 'flatrate',
            'method_code' => 'flatrate',
        ];
    }
}
