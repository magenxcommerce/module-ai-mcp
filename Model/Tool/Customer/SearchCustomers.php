<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Find customer accounts.
 */
class SearchCustomers extends AbstractTool
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_customers';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search customer accounts by email, name, group, website or signup date. Returns '
            . 'personal data, so ask for the narrowest search that answers the question rather '
            . 'than paging the customer base. Each result carries what identifies the account; '
            . 'use get_customer for the full profile and addresses.';
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
                        'description' => 'Free text matched as a substring against email, firstname '
                            . 'and lastname.',
                    ],
                    'email' => ['type' => 'string', 'description' => 'Exact match.'],
                    'group_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to one customer group. list_customer_groups '
                            . 'reports the ids.',
                    ],
                    'website_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to accounts belonging to one website.',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest signup date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as Magento stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest signup date, inclusive.'],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Field to sort by, e.g. "email". Defaults to created_at.',
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
        return 'Magento_Customer::manage';
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
            foreach (['email', 'firstname', 'lastname'] as $field) {
                $this->filterGroupBuilder->addFilter(
                    $this->filterBuilder->setField($field)
                        ->setConditionType('like')->setValue('%' . $query . '%')->create()
                );
            }
            $this->searchCriteriaBuilder->setFilterGroups([$this->filterGroupBuilder->create()]);
        }

        $email = $this->optionalString($arguments, 'email');
        if ($email !== null) {
            $this->searchCriteriaBuilder->addFilter('email', $email);
        }
        foreach (['group_id', 'website_id'] as $field) {
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

        $result = $this->customerRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($customer): array => $this->projector->toSummary($customer),
                array_values($result->getItems())
            ),
        ];
    }
}
