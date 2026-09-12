<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * List the RMA resolution types.
 */
class ListResolutionTypes extends AbstractLookupList
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ResolutionTypeRepositoryInterface $repository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly ResolutionTypeRepositoryInterface $repository
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_resolution_types';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_resolution_type';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'resolution types';
    }

    /**
     * @inheritDoc
     */
    protected function searchEntities(SearchCriteriaInterface $searchCriteria): object
    {
        return $this->repository->getList($searchCriteria);
    }
}
