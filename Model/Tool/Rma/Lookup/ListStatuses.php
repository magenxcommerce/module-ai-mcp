<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\StatusRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * List the RMA statuses.
 */
class ListStatuses extends AbstractLookupList
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param StatusRepositoryInterface $repository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly StatusRepositoryInterface $repository
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_statuses';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_status';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'statuses';
    }

    /**
     * @inheritDoc
     */
    protected function searchEntities(SearchCriteriaInterface $searchCriteria): object
    {
        return $this->repository->getList($searchCriteria);
    }
}
