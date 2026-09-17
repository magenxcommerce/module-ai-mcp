<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\Rma\CreateRma;
use Magenx\AiMcp\Model\Tool\Rma\RmaProjector;
use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\Data\ItemInterfaceFactory;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\Data\RMAInterfaceFactory;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Opening a return on a customer's behalf.
 *
 * Two checks here have no backstop anywhere below them. Nothing in the schema
 * ties an RMA item to the order the return is against, so an order_item_id from
 * a different order stores happily and produces a return nobody can fulfil; and
 * nothing caps the quantity, so a request for more than was bought reaches
 * whoever processes returns as an ordinary-looking row they will approve. Both
 * are refused here, before anything is written.
 *
 * @see CreateRma::execute
 */
class CreateRmaTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orders;
    private RMARepositoryInterface&MockObject $rmaRepository;
    private ItemRepositoryInterface&MockObject $itemRepository;
    private CreateRma $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orders = $this->createMock(OrderRepositoryInterface::class);
        $this->rmaRepository = $this->createMock(RMARepositoryInterface::class);
        $this->itemRepository = $this->createMock(ItemRepositoryInterface::class);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(42);
        $rmaFactory = $this->createMock(RMAInterfaceFactory::class);
        $rmaFactory->method('create')->willReturn($rma);
        $this->rmaRepository->method('save')->willReturn($rma);
        $this->rmaRepository->method('get')->willReturn($rma);

        $itemFactory = $this->createMock(ItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(
            fn () => $this->createMock(ItemInterface::class)
        );

        $projector = $this->createMock(RmaProjector::class);
        $projector->method('toArray')->willReturn(['rma_id' => 42]);

        $this->tool = new CreateRma(
            $this->orders,
            $rmaFactory,
            $itemFactory,
            $this->rmaRepository,
            $this->itemRepository,
            $projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheReturnManagementResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magenx_Rma::rma_manage', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAValidReturnIsCreatedWithItsLines(): void
    {
        $this->orderIs([10 => 2.0, 11 => 1.0]);
        $this->itemRepository->expects($this->exactly(2))->method('save');

        $result = $this->tool->execute([
            'order_id' => 5,
            'items' => [
                ['order_item_id' => 10, 'qty_requested' => 2],
                ['order_item_id' => 11, 'qty_requested' => 1],
            ],
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame(2, $result['items_created']);
    }

    /**
     * A line from someone else's order would store and produce a return that
     * can never be matched to goods.
     *
     * @return void
     */
    public function testAnItemFromAnotherOrderIsRefusedAndTheRealOnesNamed(): void
    {
        $this->orderIs([10 => 2.0]);
        $this->rmaRepository->expects($this->never())->method('save');

        try {
            $this->tool->execute(['order_id' => 5, 'items' => [['order_item_id' => 99, 'qty_requested' => 1]]]);
            $this->fail('An item from another order must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('not on this order', $e->getMessage());
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testReturningMoreThanWasOrderedIsRefused(): void
    {
        $this->orderIs([10 => 2.0]);
        $this->rmaRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at most 2');

        $this->tool->execute(['order_id' => 5, 'items' => [['order_item_id' => 10, 'qty_requested' => 3]]]);
    }

    /**
     * @return void
     */
    public function testAZeroOrNegativeQuantityIsRefused(): void
    {
        $this->orderIs([10 => 2.0]);

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['order_id' => 5, 'items' => [['order_item_id' => 10, 'qty_requested' => 0]]]);
    }

    /**
     * Returning exactly what was bought is the ordinary case, and must not be
     * caught by the cap above.
     *
     * @return void
     */
    public function testReturningTheWholeLineIsAllowed(): void
    {
        $this->orderIs([10 => 2.0]);
        $this->itemRepository->expects($this->once())->method('save');

        $this->tool->execute(['order_id' => 5, 'items' => [['order_item_id' => 10, 'qty_requested' => 2]]]);
    }

    /**
     * @return void
     */
    public function testAnUnknownOrderIsRefused(): void
    {
        $this->orders->method('get')->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No order exists with order_id 5');

        $this->tool->execute(['order_id' => 5, 'items' => [['order_item_id' => 10, 'qty_requested' => 1]]]);
    }

    /**
     * @return void
     */
    public function testAnEmptyItemListIsRefused(): void
    {
        $this->orderIs([10 => 2.0]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at least one line');

        $this->tool->execute(['order_id' => 5, 'items' => []]);
    }

    /**
     * Stage an order whose lines are item_id => qty_ordered.
     *
     * @param array<int, float> $lines
     * @return void
     */
    private function orderIs(array $lines): void
    {
        $items = [];
        foreach ($lines as $itemId => $qty) {
            $item = $this->createMock(OrderItemInterface::class);
            $item->method('getItemId')->willReturn($itemId);
            $item->method('getQtyOrdered')->willReturn($qty);
            $items[] = $item;
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn($items);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerId')->willReturn(7);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getCustomerFirstname')->willReturn('Ada');
        $order->method('getCustomerLastname')->willReturn('Lovelace');

        $this->orders->method('get')->willReturn($order);
    }
}
