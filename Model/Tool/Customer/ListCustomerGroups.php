<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * List the customer groups, with the ids the customer tools take.
 */
class ListCustomerGroups extends AbstractTool
{
    /**
     * @param GroupRepositoryInterface $groupRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_customer_groups';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the customer groups with their ids and tax classes. Call this before setting '
            . 'group_id on a customer, since group ids are store-specific and the defaults are not '
            . 'guaranteed to exist.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->pagingSchema(),
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
        return 'Magento_Customer::group';
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

        $result = $this->groupRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (GroupInterface $group): array => [
                    'group_id' => (int) $group->getId(),
                    'code' => $group->getCode(),
                    'tax_class_id' => $group->getTaxClassId() === null ? null : (int) $group->getTaxClassId(),
                    'tax_class_name' => $group->getTaxClassName(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
