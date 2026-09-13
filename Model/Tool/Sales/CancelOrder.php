<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Phrase;

/**
 * Cancel an order.
 */
class CancelOrder extends AbstractOrderTransition
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cancel_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cancel an order and release the stock it reserved. This cannot be undone, and '
            . 'Magento refuses it once any part of the order has been invoiced — refund those '
            . 'parts with create_credit_memo instead. Only the uninvoiced remainder is cancelled '
            . 'on a partially invoiced order.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::cancel';
    }

    /**
     * @inheritDoc
     */
    protected function applyTransition(int $orderId): bool
    {
        return (bool) $this->orderManagement->cancel($orderId);
    }

    /**
     * @inheritDoc
     */
    protected function refusalMessage(): Phrase
    {
        return __('Magento would not cancel this order; an invoiced or already closed order cannot be cancelled.');
    }
}
