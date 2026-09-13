<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\CreditmemoRepositoryInterface;

/**
 * Find credit memos — the record of what has already been refunded.
 */
class SearchCreditMemos extends AbstractDocumentSearch
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param CreditMemoProjector $creditMemoProjector
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly CreditMemoProjector $creditMemoProjector
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_credit_memos';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List credit memos, newest first, optionally narrowed to one order. Read this '
            . 'before create_credit_memo to see what has already been refunded, since a credit '
            . 'memo cannot be reversed.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::sales_creditmemo';
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
    protected function projectDocument(object $document): array
    {
        return $this->creditMemoProjector->toSummary($document);
    }
}
