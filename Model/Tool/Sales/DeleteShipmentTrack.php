<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;

/**
 * Take a tracking number off a shipment.
 */
class DeleteShipmentTrack extends AbstractTool
{
    /**
     * @param ShipmentTrackRepositoryInterface $trackRepository
     */
    public function __construct(
        private readonly ShipmentTrackRepositoryInterface $trackRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_shipment_track';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one tracking number from a shipment, by the track\'s own entity_id as '
            . 'get_shipment and search_shipments report it. The shipment itself is untouched and '
            . 'the customer is not told; a number already e-mailed to them stays in their inbox, '
            . 'so correcting a wrong one usually means adding the right one as well.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'track_id' => [
                    'type' => 'integer',
                    'description' => 'The track\'s entity_id, which get_shipment reports for each '
                        . 'track. This is not the tracking number.',
                ],
            ],
            'required' => ['track_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::ship';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $trackId = $this->requireInt($arguments, 'track_id');

        try {
            $track = $this->trackRepository->get($trackId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No shipment track exists with track_id %1.', $trackId));
        }

        // Read before deleting so the result names what went, rather than
        // confirming an id the agent may have taken from the wrong shipment.
        $removed = [
            'entity_id' => (int) $track->getEntityId(),
            'shipment_id' => (int) $track->getParentId(),
            'order_id' => (int) $track->getOrderId(),
            'track_number' => $track->getTrackNumber(),
            'carrier_code' => $track->getCarrierCode(),
            'title' => $track->getTitle(),
        ];

        $this->trackRepository->deleteById($trackId);

        return ['deleted' => true, 'track' => $removed];
    }
}
