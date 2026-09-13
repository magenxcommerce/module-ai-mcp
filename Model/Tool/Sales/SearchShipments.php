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
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * Find shipments and their tracking numbers.
 */
class SearchShipments extends AbstractDocumentSearch
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param ShipmentProjector $shipmentProjector
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShipmentProjector $shipmentProjector
    ) {
        parent::__construct($searchCriteriaBuilder, $sortOrderBuilder);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_shipments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List shipments, newest first, optionally narrowed to one order. Each result '
            . 'includes its tracking numbers and carriers.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::shipment';
    }

    /**
     * @inheritDoc
     */
    protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        return $this->shipmentRepository->getList($searchCriteria);
    }

    /**
     * @inheritDoc
     */
    protected function projectDocument(object $document): array
    {
        return $this->shipmentProjector->toSummary($document);
    }
}
