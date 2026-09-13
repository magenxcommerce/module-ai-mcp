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
     * @param InvoiceProjector $invoiceProjector
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly InvoiceProjector $invoiceProjector
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
        return $this->invoiceProjector->toSummary($document);
    }
}
