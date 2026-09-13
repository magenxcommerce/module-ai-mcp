<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Phrase;

/**
 * Release an order from hold, returning it to the state it was held from.
 */
class UnholdOrder extends AbstractOrderTransition
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'unhold_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Release an order from hold. It returns to the state it was in before the hold, '
            . 'not to "new".';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::unhold';
    }

    /**
     * @inheritDoc
     */
    protected function applyTransition(int $orderId): bool
    {
        return (bool) $this->orderManagement->unHold($orderId);
    }

    /**
     * @inheritDoc
     */
    protected function refusalMessage(): Phrase
    {
        return __('Magento would not release this order from hold.');
    }
}
