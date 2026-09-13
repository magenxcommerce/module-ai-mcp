<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterface;

/**
 * Shrinks a shipment to something worth sending to a model.
 */
class ShipmentProjector extends AbstractDocumentProjector
{
    /**
     * @param ShipmentInterface $document
     * @return array<string, mixed>
     */
    public function toSummary(object $document): array
    {
        return [
            'entity_id' => (int) $document->getEntityId(),
            'increment_id' => $document->getIncrementId(),
            'order_id' => (int) $document->getOrderId(),
            'store_id' => (int) $document->getStoreId(),
            'total_qty' => $this->money($document->getTotalQty()),
            'has_shipping_label' => $document->getShippingLabel() !== null,
            'tracks' => $this->tracks($document),
            'created_at' => $document->getCreatedAt(),
        ];
    }

    /**
     * @param ShipmentInterface $document
     * @return array<string, mixed>
     */
    public function toDetail(object $document): array
    {
        $detail = $this->toSummary($document);
        $detail['total_weight'] = $this->money($document->getTotalWeight());
        $detail['shipping_address_id'] = $document->getShippingAddressId() === null
            ? null
            : (int) $document->getShippingAddressId();
        $detail['items'] = array_map(
            fn (ShipmentItemInterface $item): array => [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => $this->money($item->getQty()),
                'weight' => $this->money($item->getWeight()),
            ],
            array_values($document->getItems() ?? [])
        );
        $detail['comments'] = $this->comments($document->getComments());

        return $detail;
    }

    /**
     * The tracking numbers on a shipment, which add_shipment_track appends to
     * and delete_shipment_track removes by entity_id.
     *
     * @param ShipmentInterface $document
     * @return array<int, array<string, mixed>>
     */
    private function tracks(ShipmentInterface $document): array
    {
        return array_map(
            static fn (ShipmentTrackInterface $track): array => [
                'entity_id' => (int) $track->getEntityId(),
                'track_number' => $track->getTrackNumber(),
                'carrier_code' => $track->getCarrierCode(),
                'title' => $track->getTitle(),
            ],
            array_values($document->getTracks() ?? [])
        );
    }
}
