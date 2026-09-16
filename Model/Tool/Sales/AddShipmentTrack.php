<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Shipping\Model\Config as ShippingConfig;

/**
 * Put a tracking number on an existing shipment.
 *
 * The normal next step after create_shipment, which Magento does not require a
 * track for — a shipment created without one leaves the customer with a
 * dispatch e-mail and nothing to follow.
 */
class AddShipmentTrack extends AbstractTool
{
    /** Magento's own escape hatch for a carrier it does not know about. */
    private const CUSTOM_CARRIER = 'custom';

    /**
     * @param ShipmentLocator $locator
     * @param ShipmentTrackRepositoryInterface $trackRepository
     * @param ShipmentTrackInterfaceFactory $trackFactory
     * @param ShipmentProjector $projector
     * @param ShippingConfig $shippingConfig
     */
    public function __construct(
        private readonly ShipmentLocator $locator,
        private readonly ShipmentTrackRepositoryInterface $trackRepository,
        private readonly ShipmentTrackInterfaceFactory $trackFactory,
        private readonly ShipmentProjector $projector,
        private readonly ShippingConfig $shippingConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_shipment_track';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a tracking number to a shipment that already exists. carrier_code has to be a '
            . 'carrier this store has configured with tracking enabled, or "custom" — an unknown '
            . 'code is refused with the list of valid ones, because Magento would otherwise store '
            . 'it and render a tracking link that goes nowhere. With "custom" the title is what the '
            . 'customer sees, so it is required. Adding a track sends no e-mail.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'carrier_code' => [
                        'type' => 'string',
                        'description' => 'Carrier code, e.g. "ups" or "dhl", or "custom" for a '
                            . 'carrier the store has not configured. Calling this with an unknown '
                            . 'code returns the codes this store accepts.',
                    ],
                    'track_number' => [
                        'type' => 'string',
                        'description' => 'The tracking number as the carrier issued it.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'What the customer sees next to the number. Defaults to the '
                            . 'carrier\'s configured title; required when carrier_code is "custom".',
                    ],
                ]
            ),
            'required' => ['carrier_code', 'track_number'],
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
    protected function isDestructive(): bool
    {
        // Adds a tracking number beside any already on the shipment.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        /** @var ShipmentInterface $shipment */
        $shipment = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'entity_id')
        );

        $carrierCode = $this->requireString($arguments, 'carrier_code');
        $trackNumber = $this->requireString($arguments, 'track_number');

        // Validated whatever the caller passed for title: a bad code stored
        // alongside a plausible-looking title is the failure worth preventing.
        $carrierTitle = $this->assertKnownCarrier($carrierCode, (int) $shipment->getStoreId());
        $title = $this->optionalString($arguments, 'title') ?? $carrierTitle;
        if ($title === null) {
            throw new LocalizedException(
                __('The "title" argument is required when carrier_code is "custom" — it is the only '
                    . 'thing naming the carrier to the customer.')
            );
        }

        $track = $this->trackFactory->create();
        $track->setParentId((int) $shipment->getEntityId());
        $track->setOrderId((int) $shipment->getOrderId());
        $track->setCarrierCode($carrierCode);
        $track->setTitle($title);
        $track->setTrackNumber($trackNumber);

        $saved = $this->trackRepository->save($track);

        return [
            'track' => [
                'entity_id' => (int) $saved->getEntityId(),
                'track_number' => $saved->getTrackNumber(),
                'carrier_code' => $saved->getCarrierCode(),
                'title' => $saved->getTitle(),
            ],
            'shipment' => $this->projector->toSummary(
                $this->locator->locate(null, (int) $shipment->getEntityId())
            ),
        ];
    }

    /**
     * Refuse a carrier code this store cannot track, and report the carrier's
     * configured title so a caller that passed none still gets a useful label.
     *
     * The candidate list is built the way Magento's own tracking form builds
     * it: every configured carrier that reports tracking as available, plus
     * "custom", which has no configured title of its own.
     *
     * @param string $carrierCode
     * @param int $storeId
     * @return string|null Null for "custom", which the caller must title itself.
     * @throws LocalizedException
     */
    private function assertKnownCarrier(string $carrierCode, int $storeId): ?string
    {
        if ($carrierCode === self::CUSTOM_CARRIER) {
            return null;
        }

        $titles = [];
        foreach ($this->shippingConfig->getAllCarriers($storeId) as $code => $carrier) {
            if ($carrier->isTrackingAvailable()) {
                $titles[(string) $code] = (string) $carrier->getConfigData('title');
            }
        }

        if (!isset($titles[$carrierCode])) {
            throw new LocalizedException(__(
                'Unknown carrier_code "%1". This store accepts: %2, custom.',
                $carrierCode,
                $titles === [] ? '(no carrier has tracking enabled)' : implode(', ', array_keys($titles))
            ));
        }

        return $titles[$carrierCode] !== '' ? $titles[$carrierCode] : $carrierCode;
    }
}
