<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * Send the order confirmation e-mail again.
 */
class SendOrderEmail extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param OrderManagementInterface $orderManagement
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly OrderManagementInterface $orderManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'send_order_email';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Send the order confirmation e-mail to the customer, for an order whose original '
            . 'never arrived. It reaches their inbox the moment this succeeds. Magento declines '
            . 'quietly rather than failing when order e-mails are switched off for the store or the '
            . 'order cannot be notified, so the result reports sent: false in that case — check it '
            . 'rather than assuming the mail went.';
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
        return 'Magento_Sales::email';
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
    public function execute(array $arguments): array
    {
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        $sent = $this->orderManagement->notify((int) $order->getEntityId());

        return [
            'sent' => $sent,
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'customer_email' => $order->getCustomerEmail(),
        ];
    }
}
