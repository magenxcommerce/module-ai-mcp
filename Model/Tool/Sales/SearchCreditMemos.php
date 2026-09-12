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
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Find credit memos — the record of what has already been refunded.
 */
class SearchCreditMemos extends AbstractDocumentSearch
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository
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
        /** @var CreditmemoInterface $document */
        return [
            'entity_id' => (int) $document->getEntityId(),
            'increment_id' => $document->getIncrementId(),
            'order_id' => (int) $document->getOrderId(),
            'store_id' => (int) $document->getStoreId(),
            // 1 = open, 2 = refunded, 3 = canceled.
            'state' => $document->getState() === null ? null : (int) $document->getState(),
            'grand_total' => $this->money($document->getGrandTotal()),
            'base_grand_total' => $this->money($document->getBaseGrandTotal()),
            'transaction_id' => $document->getTransactionId(),
            'created_at' => $document->getCreatedAt(),
        ];
    }
}
