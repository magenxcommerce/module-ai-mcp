<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\AddShipmentTrack;
use Magenx\AiMcp\Model\Tool\Sales\ShipmentLocator;
use Magenx\AiMcp\Model\Tool\Sales\ShipmentProjector;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Shipping\Model\Config as ShippingConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Putting a tracking number on a shipment.
 *
 * Magento stores whatever carrier code it is handed. A code the store does not
 * have configured produces a shipment page whose "Track this shipment" link
 * goes nowhere — a write that reports success and leaves the customer worse
 * off — so the code is checked against the store's own carriers first.
 *
 * @see AddShipmentTrack
 */
class AddShipmentTrackTest extends TestCase
{
    private ShipmentLocator&MockObject $locator;
    private ShipmentTrackRepositoryInterface&MockObject $trackRepository;
    private ShipmentTrackInterfaceFactory&MockObject $trackFactory;
    private ShippingConfig&MockObject $shippingConfig;
    private AddShipmentTrack $tool;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        GeneratedFactory::ensure(ShipmentTrackInterfaceFactory::class);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->locator = $this->createMock(ShipmentLocator::class);
        $this->trackRepository = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $this->trackFactory = $this->createMock(ShipmentTrackInterfaceFactory::class);
        $this->shippingConfig = $this->createMock(ShippingConfig::class);

        $this->locator->method('locate')->willReturn($this->shipment());

        $this->tool = new AddShipmentTrack(
            $this->locator,
            $this->trackRepository,
            $this->trackFactory,
            new ShipmentProjector(),
            $this->shippingConfig
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheShipGrant(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Sales::ship', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAnUnknownCarrierIsRefusedWithTheAcceptedCodes(): void
    {
        $this->carriersAre(['ups' => 'United Parcel Service', 'dhl' => 'DHL']);
        $this->trackRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown carrier_code "royalmail". This store accepts: ups, dhl, custom.');
        $this->tool->execute([
            'entity_id' => 5,
            'carrier_code' => 'royalmail',
            'track_number' => '1Z999',
        ]);
    }

    /**
     * A title supplied by the caller must not buy past the carrier check: a bad
     * code stored alongside a plausible label is exactly the silent failure.
     *
     * @return void
     */
    public function testATitleDoesNotExcuseAnUnknownCarrier(): void
    {
        $this->carriersAre(['ups' => 'United Parcel Service']);
        $this->trackRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->tool->execute([
            'entity_id' => 5,
            'carrier_code' => 'royalmail',
            'track_number' => '1Z999',
            'title' => 'Royal Mail',
        ]);
    }

    /**
     * @return void
     */
    public function testCustomWithoutATitleIsRefused(): void
    {
        $this->carriersAre(['ups' => 'United Parcel Service']);
        $this->trackRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The "title" argument is required when carrier_code is "custom" — it is the only thing '
            . 'naming the carrier to the customer.'
        );
        $this->tool->execute(['entity_id' => 5, 'carrier_code' => 'custom', 'track_number' => 'AB1']);
    }

    /**
     * @return void
     */
    public function testAKnownCarrierDefaultsToItsConfiguredTitle(): void
    {
        $this->carriersAre(['ups' => 'United Parcel Service']);

        $track = $this->createMock(ShipmentTrackInterface::class);
        $track->expects($this->once())->method('setTitle')->with('United Parcel Service');
        $track->expects($this->once())->method('setCarrierCode')->with('ups');
        $track->expects($this->once())->method('setTrackNumber')->with('1Z999');
        // The track hangs off the shipment, and carries the order id with it.
        $track->expects($this->once())->method('setParentId')->with(5);
        $track->expects($this->once())->method('setOrderId')->with(3);
        $track->method('getEntityId')->willReturn(11);
        $track->method('getTitle')->willReturn('United Parcel Service');
        $track->method('getCarrierCode')->willReturn('ups');
        $track->method('getTrackNumber')->willReturn('1Z999');

        $this->trackFactory->method('create')->willReturn($track);
        $this->trackRepository->expects($this->once())->method('save')->with($track)->willReturn($track);

        $result = $this->tool->execute([
            'entity_id' => 5,
            'carrier_code' => 'ups',
            'track_number' => '1Z999',
        ]);

        $this->assertSame(11, $result['track']['entity_id']);
        $this->assertSame('United Parcel Service', $result['track']['title']);
        $this->assertSame(5, $result['shipment']['entity_id']);
    }

    /**
     * A carrier the store has configured but that reports no tracking is not a
     * candidate — Magento's own tracking form leaves it out for the same reason.
     *
     * @return void
     */
    public function testACarrierWithoutTrackingIsNotACandidate(): void
    {
        $this->shippingConfig->method('getAllCarriers')->willReturn([
            'ups' => $this->carrier('United Parcel Service', true),
            'freeshipping' => $this->carrier('Free Shipping', false),
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This store accepts: ups, custom.');
        $this->tool->execute([
            'entity_id' => 5,
            'carrier_code' => 'freeshipping',
            'track_number' => '1Z999',
        ]);
    }

    /**
     * @param array<string, string> $titles
     * @return void
     */
    private function carriersAre(array $titles): void
    {
        $carriers = [];
        foreach ($titles as $code => $title) {
            $carriers[$code] = $this->carrier($title, true);
        }

        $this->shippingConfig->method('getAllCarriers')->willReturn($carriers);
    }

    /**
     * @param string $title
     * @param bool $tracking
     * @return object
     */
    private function carrier(string $title, bool $tracking): object
    {
        return new class ($title, $tracking) {
            /**
             * @param string $title
             * @param bool $tracking
             */
            public function __construct(private readonly string $title, private readonly bool $tracking)
            {
            }

            /**
             * @return bool
             */
            public function isTrackingAvailable(): bool
            {
                return $this->tracking;
            }

            /**
             * @param string $field
             * @return string
             */
            public function getConfigData($field): string
            {
                return $field === 'title' ? $this->title : '';
            }
        };
    }

    /**
     * @return ShipmentInterface&MockObject
     */
    private function shipment(): ShipmentInterface&MockObject
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getEntityId')->willReturn(5);
        $shipment->method('getOrderId')->willReturn(3);
        $shipment->method('getStoreId')->willReturn(1);
        $shipment->method('getTracks')->willReturn([]);

        return $shipment;
    }
}
