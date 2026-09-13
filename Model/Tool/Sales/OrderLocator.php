<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Turns the order argument every sales tool accepts into a loaded order.
 *
 * An agent almost always holds the increment id — "000000123" is what the admin
 * grid shows, what the confirmation email quotes and what a customer reads out —
 * while every sales service contract takes the numeric entity id instead.
 * Resolving that in one place keeps the confusion between the two out of nine
 * tools, and turns a wrong id into a tool error the agent can act on rather than
 * an uncaught exception.
 */
class OrderLocator
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Load the order named by an increment id or an entity id.
     *
     * @param string|null $incrementId
     * @param int|null $orderId
     * @return OrderInterface
     * @throws LocalizedException
     */
    public function locate(?string $incrementId, ?int $orderId): OrderInterface
    {
        if ($orderId !== null) {
            try {
                return $this->orderRepository->get($orderId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No order exists with order_id %1.', $orderId));
            }
        }

        if ($incrementId === null) {
            throw new LocalizedException(
                __('Pass increment_id (the number on the order, e.g. "000000123") or order_id.')
            );
        }

        $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId);
        $matches = array_values(
            $this->orderRepository->getList($this->searchCriteriaBuilder->create())->getItems()
        );

        if ($matches === []) {
            throw new LocalizedException(__('No order exists with increment_id "%1".', $incrementId));
        }

        // An increment id is unique per store, not per installation: two store
        // views sharing an increment prefix can both produce "000000123". Acting
        // on an arbitrary one of them and reporting success by increment id
        // would leave the agent unable to tell which order it touched.
        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The increment_id "%1" matches %2 orders (order_id %3). Pass order_id to say which one.',
                $incrementId,
                count($matches),
                implode(', ', array_map(static fn (OrderInterface $o): string => (string) $o->getEntityId(), $matches))
            ));
        }

        return $matches[0];
    }

    /**
     * Schema fragment for the two arguments that name an order.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'increment_id' => [
                'type' => 'string',
                'description' => 'The order number as shown in admin and on the customer\'s email, '
                    . 'e.g. "000000123". Either this or order_id is required.',
            ],
            'order_id' => [
                'type' => 'integer',
                'description' => 'Numeric order entity id. Use this instead of increment_id when an '
                    . 'increment id is ambiguous across store views.',
            ],
        ];
    }
}
