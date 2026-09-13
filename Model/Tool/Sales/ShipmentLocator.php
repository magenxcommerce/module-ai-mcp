<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * Loads a shipment by entity id or increment id.
 */
class ShipmentLocator extends AbstractDocumentLocator
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ShipmentRepositoryInterface $shipmentRepository
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ShipmentRepositoryInterface $shipmentRepository
    ) {
        parent::__construct($searchCriteriaBuilder);
    }

    /**
     * @inheritDoc
     */
    protected function fetchById(int $entityId): object
    {
        return $this->shipmentRepository->get($entityId);
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
    protected function documentLabel(): string
    {
        return 'shipment';
    }
}
