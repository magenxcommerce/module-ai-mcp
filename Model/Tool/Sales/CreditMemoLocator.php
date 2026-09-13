<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;

/**
 * Loads a credit memo by entity id or increment id.
 */
class CreditMemoLocator extends AbstractDocumentLocator
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository
    ) {
        parent::__construct($searchCriteriaBuilder);
    }

    /**
     * @inheritDoc
     */
    protected function fetchById(int $entityId): object
    {
        return $this->creditmemoRepository->get($entityId);
    }

    /**
     * @inheritDoc
     */
    protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        return $this->creditmemoRepository->getList($searchCriteria);
    }

    /**
     * @inheritDoc
     */
    protected function documentLabel(): string
    {
        return 'credit memo';
    }
}
