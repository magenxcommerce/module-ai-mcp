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
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
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
     */
    public function __construct(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        private readonly ShipmentRepositoryInterface $shipmentRepository
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
        /** @var ShipmentInterface $document */
        return [
            'entity_id' => (int) $document->getEntityId(),
            'increment_id' => $document->getIncrementId(),
            'order_id' => (int) $document->getOrderId(),
            'store_id' => (int) $document->getStoreId(),
            'total_qty' => $this->money($document->getTotalQty()),
            'has_shipping_label' => $document->getShippingLabel() !== null,
            'tracks' => array_map(
                static fn (ShipmentTrackInterface $track): array => [
                    'entity_id' => (int) $track->getEntityId(),
                    'track_number' => $track->getTrackNumber(),
                    'carrier_code' => $track->getCarrierCode(),
                    'title' => $track->getTitle(),
                ],
                array_values($document->getTracks() ?? [])
            ),
            'created_at' => $document->getCreatedAt(),
        ];
    }
}
