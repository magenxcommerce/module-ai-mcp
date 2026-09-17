<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Find return requests.
 */
class SearchRma extends AbstractTool
{
    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param RmaProjector $projector
     */
    public function __construct(
        private readonly RMARepositoryInterface $rmaRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly RmaProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_rma';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search return requests by status, order, customer or date, newest first. Status, '
            . 'reason and resolution type come back as ids — list_rma_statuses, list_rma_reasons '
            . 'and list_rma_resolution_types turn them into labels, and are also how to find the '
            . 'id to filter on.';
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
                        'description' => 'Free text matched as a substring against increment_id, '
                            . 'customer_email and customer_name.',
                    ],
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to one status; list_rma_statuses reports the ids.',
                    ],
                    'reason_id' => ['type' => 'integer'],
                    'resolution_type_id' => ['type' => 'integer'],
                    'order_id' => [
                        'type' => 'integer',
                        'description' => 'The numeric order entity id the return belongs to, as '
                            . 'search_orders reports under order_id.',
                    ],
                    'customer_email' => ['type' => 'string', 'description' => 'Exact match.'],
                    'customer_id' => ['type' => 'integer'],
                    'store_id' => ['type' => 'integer'],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest creation date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as the module stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest creation date, inclusive.'],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Field to sort by. Defaults to created_at.',
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
        return 'Magenx_Rma::rma_manage';
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
            // One group, three filters: OR within the group.
            foreach (['increment_id', 'customer_email', 'customer_name'] as $field) {
                $this->filterGroupBuilder->addFilter(
                    $this->filterBuilder->setField($field)
                        ->setConditionType('like')->setValue('%' . $query . '%')->create()
                );
            }
            $this->searchCriteriaBuilder->setFilterGroups([$this->filterGroupBuilder->create()]);
        }

        $email = $this->optionalString($arguments, 'customer_email');
        if ($email !== null) {
            $this->searchCriteriaBuilder->addFilter('customer_email', $email);
        }
        foreach (['status_id', 'reason_id', 'resolution_type_id', 'order_id', 'customer_id', 'store_id'] as $field) {
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

        $sortBy = $this->optionalString($arguments, 'sort_by', 'created_at');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField((string) $sortBy)
                ->setDirection($direction === 'ASC' ? 'ASC' : 'DESC')
                ->create()
        );

        $result = $this->rmaRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($rma): array => $this->projector->toArray($rma),
                array_values($result->getItems())
            ),
        ];
    }
}
