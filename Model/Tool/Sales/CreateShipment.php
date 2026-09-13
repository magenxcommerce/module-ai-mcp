<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;

/**
 * Ship an order, with tracking numbers.
 */
class CreateShipment extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param LineItemArguments $lineItems
     * @param ShipOrderInterface $shipOrder
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param ShipmentItemCreationInterfaceFactory $itemFactory
     * @param ShipmentCommentCreationInterfaceFactory $commentFactory
     * @param ShipmentTrackCreationInterfaceFactory $trackFactory
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly LineItemArguments $lineItems,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShipmentItemCreationInterfaceFactory $itemFactory,
        private readonly ShipmentCommentCreationInterfaceFactory $commentFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_shipment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Ship an order. Omit items to ship everything still shippable, or pass items to '
            . 'ship specific lines — call get_order first and read each line\'s qty_shippable. '
            . 'Magento allows shipping before invoicing, so a shippable quantity does not imply '
            . 'the line has been paid for. Tracking numbers can be added here; a shipment cannot '
            . 'be deleted once created.';
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
                    'items' => $this->lineItems->schemaProperty(
                        'Lines to ship. Omit to ship every remaining shippable quantity.'
                    ),
                    'tracks' => [
                        'type' => 'array',
                        'description' => 'Tracking numbers to attach to this shipment.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'track_number' => [
                                    'type' => 'string',
                                    'description' => 'The carrier\'s tracking number.',
                                ],
                                'carrier_code' => [
                                    'type' => 'string',
                                    'description' => 'Carrier code, e.g. "ups", "fedex", "dhl", or '
                                        . '"custom" for a carrier Magento does not know. Defaults to "custom".',
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Carrier name shown to the customer. Required '
                                        . 'when carrier_code is "custom".',
                                ],
                            ],
                            'required' => ['track_number'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'notify_customer' => [
                        'type' => 'boolean',
                        'description' => 'Email the shipment to the customer. Default false.',
                    ],
                    'comment' => ['type' => 'string', 'description' => 'Optional comment stored on the shipment.'],
                    'comment_visible_on_front' => [
                        'type' => 'boolean',
                        'description' => 'Show the comment in the customer\'s account. Default false.',
                    ],
                ]
            ),
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
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        $items = [];
        foreach ($this->lineItems->normalize($this->optionalArray($arguments, 'items')) as $orderItemId => $qty) {
            $creation = $this->itemFactory->create();
            $creation->setOrderItemId($orderItemId);
            $creation->setQty($qty);
            $items[] = $creation;
        }

        $tracks = $this->buildTracks($this->optionalArray($arguments, 'tracks'));

        $commentText = $this->optionalString($arguments, 'comment');
        $comment = null;
        if ($commentText !== null) {
            $comment = $this->commentFactory->create();
            $comment->setComment($commentText);
            $comment->setIsVisibleOnFront(
                $this->optionalBool($arguments, 'comment_visible_on_front', false) ? 1 : 0
            );
        }

        $notify = (bool) $this->optionalBool($arguments, 'notify_customer', false);

        $shipmentId = (int) $this->shipOrder->execute(
            (int) $order->getEntityId(),
            $items,
            $notify,
            $comment !== null,
            $comment,
            $tracks
        );

        $shipment = $this->shipmentRepository->get($shipmentId);
        $updated = $this->locator->locate(null, (int) $order->getEntityId());

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'shipment_id' => $shipmentId,
            'shipment_increment_id' => $shipment->getIncrementId(),
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $updated->getIncrementId(),
            'total_qty' => $shipment->getTotalQty() === null ? null : (float) $shipment->getTotalQty(),
            'tracks' => array_map(
                static fn (ShipmentTrackInterface $track): array => [
                    'track_number' => $track->getTrackNumber(),
                    'carrier_code' => $track->getCarrierCode(),
                    'title' => $track->getTitle(),
                ],
                array_values($shipment->getTracks() ?? [])
            ),
            'customer_notified' => $notify,
            'order_state' => $updated->getState(),
            'order_status' => $updated->getStatus(),
        ];
    }

    /**
     * Build the tracking objects, refusing an entry Magento would store blank.
     *
     * A track saved without a number is a row the customer sees as a tracking
     * link that resolves to nothing, so an empty number is an error rather than
     * a silently dropped entry.
     *
     * @param array<mixed> $tracks
     * @return \Magento\Sales\Api\Data\ShipmentTrackCreationInterface[]
     * @throws LocalizedException
     */
    private function buildTracks(array $tracks): array
    {
        $built = [];
        foreach ($tracks as $index => $track) {
            if (!is_array($track)) {
                throw new LocalizedException(
                    __('Track %1 must be an object with a track_number.', (int) $index + 1)
                );
            }

            $number = $track['track_number'] ?? null;
            if (!is_string($number) || trim($number) === '') {
                throw new LocalizedException(__('Track %1 needs a "track_number".', (int) $index + 1));
            }

            $carrierCode = $track['carrier_code'] ?? null;
            $carrierCode = is_string($carrierCode) && trim($carrierCode) !== '' ? trim($carrierCode) : 'custom';

            $title = $track['title'] ?? null;
            $title = is_string($title) && trim($title) !== '' ? trim($title) : null;
            if ($carrierCode === 'custom' && $title === null) {
                throw new LocalizedException(__(
                    'Track %1 uses carrier_code "custom", which needs a "title" naming the carrier '
                    . 'for the customer.',
                    (int) $index + 1
                ));
            }

            $creation = $this->trackFactory->create();
            $creation->setTrackNumber(trim($number));
            $creation->setCarrierCode($carrierCode);
            $creation->setTitle($title ?? $carrierCode);
            $built[] = $creation;
        }

        return $built;
    }
}
