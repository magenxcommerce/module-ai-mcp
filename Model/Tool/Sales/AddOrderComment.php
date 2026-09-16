<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * Add a comment to an order, optionally changing its status and emailing the
 * customer.
 */
class AddOrderComment extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param OrderManagementInterface $orderManagement
     * @param OrderStatusHistoryInterfaceFactory $historyFactory
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderStatusHistoryInterfaceFactory $historyFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_order_comment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a comment to an order\'s history. Passing status also changes the order\'s '
            . 'status, which Magento restricts to statuses belonging to the order\'s current '
            . 'state. Set notify_customer to email the comment to the customer, and '
            . 'visible_on_front to show it in their account.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'comment' => ['type' => 'string', 'description' => 'The comment text.'],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional new status code. Must be one of the statuses '
                            . 'assigned to the order\'s current state, or the call is refused. '
                            . 'Omit to leave the status unchanged.',
                    ],
                    'notify_customer' => [
                        'type' => 'boolean',
                        'description' => 'Email the comment to the customer. Default false.',
                    ],
                    'visible_on_front' => [
                        'type' => 'boolean',
                        'description' => 'Show the comment in the customer\'s account. Default false.',
                    ],
                ]
            ),
            'required' => ['comment'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::comment';
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
        // Appends a comment to the order history; nothing is overwritten.
        return false;
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
        $comment = $this->requireString($arguments, 'comment');
        $status = $this->optionalString($arguments, 'status');
        $notify = $this->optionalBool($arguments, 'notify_customer', false);
        $visible = $this->optionalBool($arguments, 'visible_on_front', false);

        $history = $this->historyFactory->create();
        $history->setParentId((int) $order->getEntityId());
        $history->setComment($comment);
        $history->setIsCustomerNotified($notify);
        $history->setIsVisibleOnFront($visible);
        $history->setEntityName('order');
        if ($status !== null) {
            $history->setStatus($status);
        }

        $this->orderManagement->addComment((int) $order->getEntityId(), $history);

        $updated = $this->locator->locate(null, (int) $order->getEntityId());

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'order_id' => (int) $updated->getEntityId(),
            'increment_id' => $updated->getIncrementId(),
            'state' => $updated->getState(),
            'status' => $updated->getStatus(),
            'customer_notified' => $notify,
            'visible_on_front' => $visible,
        ];
    }
}
