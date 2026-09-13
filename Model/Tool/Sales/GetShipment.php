<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\ShipmentInterface;

/**
 * Read one shipment in full.
 */
class GetShipment extends AbstractTool
{
    /**
     * @param ShipmentLocator $locator
     * @param ShipmentProjector $projector
     */
    public function __construct(
        private readonly ShipmentLocator $locator,
        private readonly ShipmentProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_shipment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one shipment by entity_id or its own increment_id: lines, comments and every '
            . 'tracking number on it. Each track reports the entity_id that delete_shipment_track '
            . 'takes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
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
    public function execute(array $arguments): array
    {
        /** @var ShipmentInterface $shipment */
        $shipment = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'entity_id')
        );

        return $this->projector->toDetail($shipment);
    }
}
