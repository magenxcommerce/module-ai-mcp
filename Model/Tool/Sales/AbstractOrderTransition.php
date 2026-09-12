<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * Shared behaviour for the state changes that take no arguments but the order:
 * hold, unhold and cancel.
 *
 * Each is one call to {@see OrderManagementInterface} that returns a plain
 * boolean, and that boolean is the reason this base class exists. Magento
 * answers `false` when an order is not in a state the transition allows —
 * cancelling an order that is already complete, holding one that is already on
 * hold — without throwing. Returning that as a successful tool result would
 * tell an agent the order was cancelled when it was not, so a false is turned
 * into a tool error here, once, for all three.
 */
abstract class AbstractOrderTransition extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param OrderManagementInterface $orderManagement
     */
    public function __construct(
        protected readonly OrderLocator $locator,
        protected readonly OrderManagementInterface $orderManagement
    ) {
    }

    /**
     * Attempt the transition. Returns Magento's own verdict.
     *
     * @param int $orderId
     * @return bool
     */
    abstract protected function applyTransition(int $orderId): bool;

    /**
     * What to tell the agent when Magento refuses the transition. The order's
     * current state is appended by the caller.
     *
     * @return Phrase
     */
    abstract protected function refusalMessage(): Phrase;

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
        $orderId = (int) $order->getEntityId();
        $stateBefore = (string) $order->getState();

        if ($this->applyTransition($orderId) !== true) {
            throw new LocalizedException(
                __(
                    '%1 The order is in state "%2" with status "%3".',
                    $this->refusalMessage()->render(),
                    $stateBefore,
                    (string) $order->getStatus()
                )
            );
        }

        // The repository hands back the instance the transition mutated, so
        // this reports the state Magento actually settled on rather than the
        // one this tool assumed it would produce.
        $updated = $this->locator->locate(null, $orderId);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'order_id' => $orderId,
            'increment_id' => $updated->getIncrementId(),
            'state_before' => $stateBefore,
            'state' => $updated->getState(),
            'status' => $updated->getStatus(),
        ];
    }
}
