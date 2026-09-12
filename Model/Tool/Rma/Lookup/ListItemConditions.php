<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * List the RMA item conditions.
 */
class ListItemConditions extends AbstractLookupList
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ItemConditionRepositoryInterface $repository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly ItemConditionRepositoryInterface $repository
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_item_conditions';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_item_condition';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'item conditions';
    }

    /**
     * @inheritDoc
     */
    protected function searchEntities(SearchCriteriaInterface $searchCriteria): object
    {
        return $this->repository->getList($searchCriteria);
    }
}
