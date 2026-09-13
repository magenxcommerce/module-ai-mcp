<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

/**
 * Loads an invoice by entity id or increment id.
 */
class InvoiceLocator extends AbstractDocumentLocator
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param InvoiceRepositoryInterface $invoiceRepository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly InvoiceRepositoryInterface $invoiceRepository
    ) {
        parent::__construct($searchCriteriaBuilder);
    }

    /**
     * @inheritDoc
     */
    protected function fetchById(int $entityId): object
    {
        return $this->invoiceRepository->get($entityId);
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
    protected function documentLabel(): string
    {
        return 'invoice';
    }
}
