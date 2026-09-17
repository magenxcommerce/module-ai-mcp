<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Find orders by status, customer, store or date.
 */
class SearchOrders extends AbstractTool
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param OrderProjector $projector
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly OrderProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_orders';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search orders by state, status, customer, store or creation date. Returns a compact '
            . 'summary per order, newest first; use get_order for items, addresses and the '
            . 'quantities that can still be invoiced, shipped or refunded.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Free text matched as a substring against increment_id and '
                            . 'customer_email.',
                    ],
                    'state' => [
                        'type' => 'string',
                        'description' => 'Order state: new, pending_payment, processing, complete, '
                            . 'closed, canceled, holded, payment_review.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Order status code, which is store-configurable and finer '
                            . 'grained than state, e.g. "pending" or "fraud".',
                    ],
                    'customer_email' => ['type' => 'string', 'description' => 'Exact match.'],
                    'customer_id' => ['type' => 'integer'],
                    'store_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to one store view. Call list_stores for the ids.',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest creation date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as Magento stores it.',
                    ],
                    'created_to' => [
                        'type' => 'string',
                        'description' => 'Latest creation date, inclusive.',
                    ],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Field to sort by, e.g. "grand_total". Defaults to created_at.',
                    ],
                    'sort_direction' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            // One group, two filters: OR within the group.
            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->setField('increment_id')
                    ->setConditionType('like')->setValue('%' . $query . '%')->create()
            );
            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->setField('customer_email')
                    ->setConditionType('like')->setValue('%' . $query . '%')->create()
            );
            $this->searchCriteriaBuilder->setFilterGroups([$this->filterGroupBuilder->create()]);
        }

        foreach (['state', 'status', 'customer_email'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $this->searchCriteriaBuilder->addFilter($field, $value);
            }
        }
        foreach (['customer_id', 'store_id'] as $field) {
            $value = $this->optionalInt($arguments, $field);
            if ($value !== null) {
                $this->searchCriteriaBuilder->addFilter($field, $value);
            }
        }

        $createdFrom = $this->optionalString($arguments, 'created_from');
        if ($createdFrom !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdFrom, 'gteq');
        }
        $createdTo = $this->optionalString($arguments, 'created_to');
        if ($createdTo !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdTo, 'lteq');
        }

        // Newest first is what an operator means by "the orders"; an unsorted
        // page of orders is entity_id order, which is near-useless here.
        $sortBy = $this->optionalString($arguments, 'sort_by', 'created_at');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField((string) $sortBy)
                ->setDirection($direction === 'ASC' ? 'ASC' : 'DESC')
                ->create()
        );

        $result = $this->orderRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($order): array => $this->projector->toSummary($order),
                array_values($result->getItems())
            ),
        ];
    }
}
