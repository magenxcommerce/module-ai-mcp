<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\ItemInterfaceFactory;
use Magenx\Rma\Api\Data\RMAInterfaceFactory;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Open a return on a customer's behalf.
 *
 * The admin has had this since the module shipped; this server only had update
 * and delete, so an agent could manage a return it could not start — which is
 * the half that comes up on a support call.
 *
 * Items are named by order item, not by sku: an order can carry the same sku on
 * more than one line at different prices or with different options, and a
 * return has to point at the line that was actually bought. get_order reports
 * the item_id for each line.
 */
class CreateRma extends AbstractTool
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param RMAInterfaceFactory $rmaFactory
     * @param ItemInterfaceFactory $itemFactory
     * @param RMARepositoryInterface $rmaRepository
     * @param ItemRepositoryInterface $itemRepository
     * @param RmaProjector $projector
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RMAInterfaceFactory $rmaFactory,
        private readonly ItemInterfaceFactory $itemFactory,
        private readonly RMARepositoryInterface $rmaRepository,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly RmaProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_rma';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Open a return request against an order, with the lines being returned and how many '
            . 'of each. Items are named by the order item_id that get_order reports, not by sku, '
            . 'because an order can carry the same sku on several lines. The quantity requested '
            . 'cannot exceed what was ordered on that line. Creating a return does not refund '
            . 'anything and does not email the customer — it records the request; '
            . 'create_credit_memo is what moves money.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => [
                    'type' => 'integer',
                    'description' => 'The order being returned against. get_order reports it as '
                        . 'entity_id.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'The lines being returned.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'order_item_id' => [
                                'type' => 'integer',
                                'description' => 'The item_id of the order line, from get_order.',
                            ],
                            'qty_requested' => [
                                'type' => 'number',
                                'description' => 'How many of that line the customer is returning.',
                            ],
                            'condition_id' => [
                                'type' => 'integer',
                                'description' => 'Optional item condition; '
                                    . 'list_rma_item_conditions reports the ids.',
                            ],
                        ],
                        'required' => ['order_item_id', 'qty_requested'],
                        'additionalProperties' => false,
                    ],
                ],
                'reason_id' => [
                    'type' => 'integer',
                    'description' => 'Why it is coming back; list_rma_reasons reports the ids.',
                ],
                'resolution_type_id' => [
                    'type' => 'integer',
                    'description' => 'What the customer wants; list_rma_resolution_types reports '
                        . 'the ids.',
                ],
                'status_id' => [
                    'type' => 'integer',
                    'description' => 'Starting status. Omit to let the module use its default; '
                        . 'list_rma_statuses reports the ids.',
                ],
            ],
            'required' => ['order_id', 'items'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function isDestructive(): bool
    {
        // Adds a return; touches nothing that already exists.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $orderId = $this->requireInt($arguments, 'order_id');

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No order exists with order_id %1.', $orderId));
        }

        $lines = $this->readItems($arguments, $order->getItems());

        $rma = $this->rmaFactory->create();
        $rma->setOrderId($orderId);
        $rma->setStoreId((int) $order->getStoreId());
        $rma->setCustomerId($order->getCustomerId() === null ? null : (int) $order->getCustomerId());
        $rma->setCustomerEmail((string) $order->getCustomerEmail());
        $rma->setCustomerName(trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()));

        $optional = [
            'reason_id' => 'setReasonId',
            'resolution_type_id' => 'setResolutionTypeId',
            'status_id' => 'setStatusId',
        ];
        foreach ($optional as $argument => $setter) {
            $value = $this->optionalInt($arguments, $argument);
            if ($value !== null) {
                $rma->{$setter}($value);
            }
        }

        $saved = $this->rmaRepository->save($rma);

        foreach ($lines as $line) {
            $item = $this->itemFactory->create();
            $item->setRmaId((int) $saved->getEntityId());
            $item->setOrderItemId($line['order_item_id']);
            $item->setQtyRequested($line['qty_requested']);
            if ($line['condition_id'] !== null) {
                $item->setConditionId($line['condition_id']);
            }
            $this->itemRepository->save($item);
        }

        return [
            'created' => true,
            'tool' => $this->getName(),
            'items_created' => count($lines),
        ] + $this->projector->toArray($this->rmaRepository->get((int) $saved->getEntityId()));
    }

    /**
     * Read and check the requested lines against the order.
     *
     * Both checks here exist because neither the schema nor the module enforces
     * them: an order_item_id from a different order would store and produce a
     * return nobody can fulfil, and a quantity above what was bought would be
     * approved by whoever processes it without anyone noticing the arithmetic.
     *
     * @param array<string, mixed> $arguments
     * @param OrderItemInterface[]|null $orderItems
     * @return array<int, array<string, mixed>>
     * @throws LocalizedException
     */
    private function readItems(array $arguments, ?array $orderItems): array
    {
        $requested = $arguments['items'] ?? null;
        if (!is_array($requested) || $requested === []) {
            throw new LocalizedException(__('Pass "items" with at least one line to return.'));
        }

        $ordered = [];
        foreach ($orderItems ?? [] as $orderItem) {
            $ordered[(int) $orderItem->getItemId()] = $orderItem;
        }

        $lines = [];
        foreach ($requested as $line) {
            if (!is_array($line)) {
                throw new LocalizedException(__('Every entry in "items" must be an object.'));
            }

            $itemId = $line['order_item_id'] ?? null;
            if (!is_int($itemId) && !(is_string($itemId) && ctype_digit($itemId))) {
                throw new LocalizedException(__('Every item needs an "order_item_id" whole number.'));
            }
            $itemId = (int) $itemId;

            if (!isset($ordered[$itemId])) {
                throw new LocalizedException(__(
                    'Order item %1 is not on this order. get_order lists the item_id of each line; '
                    . 'this order has: %2.',
                    $itemId,
                    $ordered === [] ? '(none)' : implode(', ', array_keys($ordered))
                ));
            }

            $qty = $line['qty_requested'] ?? null;
            if (!is_int($qty) && !is_float($qty) && !(is_string($qty) && is_numeric($qty))) {
                throw new LocalizedException(__('Every item needs a "qty_requested" number.'));
            }
            $qty = (float) $qty;

            $availableQty = (float) $ordered[$itemId]->getQtyOrdered();
            if ($qty <= 0 || $qty > $availableQty) {
                throw new LocalizedException(__(
                    'qty_requested for order item %1 must be above 0 and at most %2, which is what '
                    . 'was ordered on that line.',
                    $itemId,
                    $availableQty
                ));
            }

            $conditionId = $line['condition_id'] ?? null;
            $lines[] = [
                'order_item_id' => $itemId,
                'qty_requested' => $qty,
                'condition_id' => $conditionId === null ? null : (int) $conditionId,
            ];
        }

        return $lines;
    }
}
