<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;

/**
 * Read an order's comment and status history.
 */
class ListOrderComments extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     */
    public function __construct(
        private readonly OrderLocator $locator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_order_comments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the comment and status history of one order, newest first: what was written, '
            . 'which status it was set to, and whether the customer was notified. Excluded from '
            . 'get_order because the history of a long-running order is large.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::actions_view';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        $history = array_values($order->getStatusHistories() ?? []);
        // Magento returns history oldest first; the recent entries are the ones
        // that matter and the ones that survive a truncated read.
        usort(
            $history,
            static fn (OrderStatusHistoryInterface $a, OrderStatusHistoryInterface $b): int
                => (int) $b->getEntityId() <=> (int) $a->getEntityId()
        );

        return [
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'total_count' => count($history),
            'items' => array_map(
                static fn (OrderStatusHistoryInterface $entry): array => [
                    'entity_id' => (int) $entry->getEntityId(),
                    'entity_name' => $entry->getEntityName(),
                    'status' => $entry->getStatus(),
                    'comment' => $entry->getComment(),
                    'is_customer_notified' => $entry->getIsCustomerNotified() === null
                        ? null
                        : (bool) $entry->getIsCustomerNotified(),
                    'is_visible_on_front' => (bool) $entry->getIsVisibleOnFront(),
                    'created_at' => $entry->getCreatedAt(),
                ],
                $history
            ),
        ];
    }
}
