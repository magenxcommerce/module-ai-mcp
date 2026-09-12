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
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

/**
 * Find invoices, optionally for one order.
 */
class SearchInvoices extends AbstractDocumentSearch
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param InvoiceRepositoryInterface $invoiceRepository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly InvoiceRepositoryInterface $invoiceRepository
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_invoices';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List invoices, newest first, optionally narrowed to one order. Pass an invoice\'s '
            . 'entity_id to create_credit_memo to refund against it online.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::sales_invoice';
    }

    /**
     * @inheritDoc
     */
    protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        return $this->invoiceRepository->getList($searchCriteria);
    }

    /**
     * @inheritDoc
     */
    protected function projectDocument(object $document): array
    {
        /** @var InvoiceInterface $document */
        return [
            'entity_id' => (int) $document->getEntityId(),
            'increment_id' => $document->getIncrementId(),
            'order_id' => (int) $document->getOrderId(),
            'store_id' => (int) $document->getStoreId(),
            // 1 = open, 2 = paid, 3 = canceled.
            'state' => $document->getState() === null ? null : (int) $document->getState(),
            'grand_total' => $this->money($document->getGrandTotal()),
            'base_grand_total' => $this->money($document->getBaseGrandTotal()),
            'total_qty' => $this->money($document->getTotalQty()),
            'total_refunded' => $this->money($document->getBaseTotalRefunded()),
            'transaction_id' => $document->getTransactionId(),
            'created_at' => $document->getCreatedAt(),
        ];
    }
}
