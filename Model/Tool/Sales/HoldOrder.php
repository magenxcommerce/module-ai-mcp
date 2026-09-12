<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Phrase;

/**
 * Put an order on hold, stopping further processing until it is released.
 */
class HoldOrder extends AbstractOrderTransition
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'hold_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Put an order on hold so it cannot be invoiced or shipped until unhold_order '
            . 'releases it. Reversible, and it does not notify the customer.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::hold';
    }

    /**
     * @inheritDoc
     */
    protected function applyTransition(int $orderId): bool
    {
        return (bool) $this->orderManagement->hold($orderId);
    }

    /**
     * @inheritDoc
     */
    protected function refusalMessage(): Phrase
    {
        return __('Magento would not put this order on hold.');
    }
}
