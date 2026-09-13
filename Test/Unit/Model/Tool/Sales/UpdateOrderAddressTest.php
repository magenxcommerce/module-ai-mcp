<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\OrderAddresses;
use Magenx\AiMcp\Model\Tool\Sales\OrderLocator;
use Magenx\AiMcp\Model\Tool\Sales\UpdateOrderAddress;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Correcting an order address.
 *
 * The address is reached through the order and never by an id the caller
 * supplies, so the failures worth pinning down are the ones that remain: a side
 * of the order that does not exist, and a field set the caller did not mean.
 *
 * @see UpdateOrderAddress
 */
class UpdateOrderAddressTest extends TestCase
{
    private OrderLocator&MockObject $locator;
    private OrderAddressRepositoryInterface&MockObject $addressRepository;
    private UpdateOrderAddress $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(OrderLocator::class);
        $this->addressRepository = $this->createMock(OrderAddressRepositoryInterface::class);
        $this->tool = new UpdateOrderAddress(
            $this->locator,
            new OrderAddresses(),
            $this->addressRepository
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheOrderEditGrant(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Sales::actions_edit', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAnUnknownAddressTypeIsRefused(): void
    {
        $this->locateReturns($this->order(null));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "address_type" argument must be "billing" or "shipping".');
        $this->tool->execute(['order_id' => 1, 'address_type' => 'pickup', 'city' => 'Berlin']);
    }

    /**
     * A virtual order has no shipping address at all. Falling back to the
     * billing address here would silently edit the wrong one.
     *
     * @return void
     */
    public function testAVirtualOrderHasNoShippingAddress(): void
    {
        $this->locateReturns($this->order(null));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Order 000000123 has no shipping address. An order of only virtual or downloadable '
            . 'products never has a shipping address.'
        );
        $this->tool->execute(['order_id' => 1, 'address_type' => 'shipping', 'city' => 'Berlin']);
    }

    /**
     * @return void
     */
    public function testNothingToChangeIsRefusedRatherThanSaved(): void
    {
        $this->locateReturns($this->order($this->createMock(OrderAddressInterface::class)));
        $this->addressRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pass at least one address field to change; nothing was supplied.');
        $this->tool->execute(['order_id' => 1, 'address_type' => 'billing']);
    }

    /**
     * @return void
     */
    public function testAnEmptyStreetIsRefused(): void
    {
        $this->locateReturns($this->order($this->createMock(OrderAddressInterface::class)));
        $this->addressRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "street" argument must hold at least one non-empty line.');
        $this->tool->execute(['order_id' => 1, 'address_type' => 'billing', 'street' => ['', '  ']]);
    }

    /**
     * Omitted means unchanged: the tool mutates a loaded address, so a field it
     * does not touch keeps whatever the order already carries.
     *
     * @return void
     */
    public function testOnlySuppliedFieldsAreWritten(): void
    {
        $address = $this->createMock(OrderAddressInterface::class);
        $address->expects($this->once())->method('setCity')->with('Berlin');
        $address->expects($this->once())->method('setPostcode')->with('10115');
        $address->expects($this->once())->method('setStreet')->with(['Chausseestr. 1', 'Hinterhof']);
        $address->expects($this->never())->method('setCountryId');
        $address->expects($this->never())->method('setTelephone');
        $address->method('getEntityId')->willReturn(42);

        $this->locateReturns($this->order($address));
        $this->addressRepository->expects($this->once())
            ->method('save')
            ->with($address)
            ->willReturn($address);

        $result = $this->tool->execute([
            'order_id' => 1,
            'address_type' => 'billing',
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => ['Chausseestr. 1', ' Hinterhof '],
        ]);

        $this->assertSame(['city', 'postcode', 'street'], $result['changed_fields']);
        $this->assertSame(42, $result['address_id']);
        $this->assertSame('billing', $result['address_type']);
        $this->assertSame('000000123', $result['increment_id']);
    }

    /**
     * @param OrderInterface $order
     * @return void
     */
    private function locateReturns(OrderInterface $order): void
    {
        $this->locator->method('locate')->willReturn($order);
    }

    /**
     * An order whose billing address is the one supplied and which has no
     * shipping assignment, as a virtual order does not.
     *
     * @param OrderAddressInterface|null $billing
     * @return OrderInterface&MockObject
     */
    private function order(?OrderAddressInterface $billing): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getExtensionAttributes')->willReturn(null);

        return $order;
    }
}
