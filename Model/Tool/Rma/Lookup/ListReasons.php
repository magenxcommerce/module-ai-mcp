<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * List the RMA reasons.
 */
class ListReasons extends AbstractLookupList
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ReasonRepositoryInterface $repository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly ReasonRepositoryInterface $repository
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_reasons';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_reason';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'reasons';
    }

    /**
     * @inheritDoc
     */
    protected function searchEntities(SearchCriteriaInterface $searchCriteria): object
    {
        return $this->repository->getList($searchCriteria);
    }
}
