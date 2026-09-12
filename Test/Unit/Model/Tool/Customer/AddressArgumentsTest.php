<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\Customer\AddressArguments;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The address fields shared by the create and update address tools.
 *
 * @see AddressArguments
 */
class AddressArgumentsTest extends TestCase
{
    private RegionInterfaceFactory&MockObject $regionFactory;

    private AddressArguments $addressArguments;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // Magento generates this factory rather than shipping it, so outside a
        // Magento installation the name has no definition to mock.
        GeneratedFactory::ensure(RegionInterfaceFactory::class);

        $this->regionFactory = $this->createMock(RegionInterfaceFactory::class);
        $this->addressArguments = new AddressArguments($this->regionFactory);
    }

    /**
     * @return void
     */
    public function testOnlyThePassedFieldsAreSet(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->expects($this->once())->method('setCity')->with('Berlin');
        $address->expects($this->once())->method('setPostcode')->with('10115');
        // Nothing else was passed, so nothing else may be written — an update
        // that blanks a field the caller never mentioned is the failure this
        // guards against.
        $address->expects($this->never())->method('setTelephone');
        $address->expects($this->never())->method('setStreet');

        $changed = $this->addressArguments->applyTo($address, ['city' => 'Berlin', 'postcode' => '10115']);

        $this->assertSame(['city', 'postcode'], $changed);
    }

    /**
     * A model sends a one-line address as a plain string as often as a list.
     *
     * @return void
     */
    public function testASingleStreetStringBecomesOneLine(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->expects($this->once())->method('setStreet')->with(['Hauptstr. 1']);

        $this->addressArguments->applyTo($address, ['street' => 'Hauptstr. 1']);
    }

    /**
     * @return void
     */
    public function testStreetLinesArePassedThrough(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $address->expects($this->once())->method('setStreet')->with(['Line 1', 'Line 2']);

        $this->addressArguments->applyTo($address, ['street' => ['Line 1', 'Line 2']]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('malformedArgumentsProvider')]
    public function testMalformedArgumentsAreRefused(array $arguments, string $expectedMessage): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->addressArguments->applyTo($this->createMock(AddressInterface::class), $arguments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedArgumentsProvider(): array
    {
        return [
            'empty street' => [
                ['street' => []],
                'The "street" argument must be a street line or a list of street lines.',
            ],
            'street line is not a string' => [
                ['street' => [['nested']]],
                'Every "street" line must be a string.',
            ],
            'scalar given an array' => [
                ['city' => ['Berlin']],
                'The "city" argument must be a string.',
            ],
            // "false" and 1 are the shapes a model actually produces for a
            // boolean, and both would silently become true under a cast.
            'default flag as string' => [
                ['is_default_billing' => 'true'],
                'must be true or false',
            ],
            'default flag as number' => [
                ['is_default_shipping' => 1],
                'must be true or false',
            ],
            'region id not a number' => [
                ['region_id' => 'abc'],
                'The "region_id" argument must be a whole number.',
            ],
            'region name not a string' => [
                ['region' => 42],
                'The "region" argument must be a string.',
            ],
        ];
    }

    /**
     * Magento stores the region as both a numeric id and a name and resolves an
     * address by the id. Replacing one without carrying the other over would
     * leave the two disagreeing.
     *
     * @return void
     */
    public function testChangingTheRegionIdKeepsTheExistingName(): void
    {
        $existing = $this->createMock(RegionInterface::class);
        $existing->method('getRegion')->willReturn('Berlin');
        $existing->method('getRegionCode')->willReturn('BE');

        $written = $this->expectRegionWritten();

        $address = $this->createMock(AddressInterface::class);
        $address->method('getRegion')->willReturn($existing);
        $address->method('getRegionId')->willReturn(82);
        $address->expects($this->once())->method('setRegionId')->with(90);

        $changed = $this->addressArguments->applyTo($address, ['region_id' => 90]);

        $this->assertSame(['region_id'], $changed);
        $this->assertSame(90, $written->getRegionId());
        $this->assertSame('Berlin', $written->getRegion());
        $this->assertSame('BE', $written->getRegionCode());
    }

    /**
     * @return void
     */
    public function testChangingTheRegionNameKeepsTheExistingId(): void
    {
        $existing = $this->createMock(RegionInterface::class);
        $existing->method('getRegion')->willReturn('Berlin');

        $written = $this->expectRegionWritten();

        $address = $this->createMock(AddressInterface::class);
        $address->method('getRegion')->willReturn($existing);
        $address->method('getRegionId')->willReturn(82);
        $address->expects($this->once())->method('setRegionId')->with(82);

        $this->addressArguments->applyTo($address, ['region' => 'Bavaria']);

        $this->assertSame(82, $written->getRegionId());
        $this->assertSame('Bavaria', $written->getRegion());
    }

    /**
     * An empty result is what update_customer_address turns into "nothing to
     * update" rather than an empty save.
     *
     * @return void
     */
    public function testIdentifierOnlyArgumentsChangeNothing(): void
    {
        $changed = $this->addressArguments->applyTo(
            $this->createMock(AddressInterface::class),
            ['address_id' => 5]
        );

        $this->assertSame([], $changed);
    }

    /**
     * A region object that records what was written to it, so a test can assert
     * on the merge rather than on the call arguments.
     *
     * @return RegionInterface
     */
    private function expectRegionWritten(): RegionInterface
    {
        $region = new class implements RegionInterface {
            private ?string $code = null;
            private ?string $region = null;
            private ?int $id = null;

            public function getRegionCode()
            {
                return $this->code;
            }

            public function setRegionCode($regionCode)
            {
                $this->code = $regionCode;
                return $this;
            }

            public function getRegion()
            {
                return $this->region;
            }

            public function setRegion($region)
            {
                $this->region = $region;
                return $this;
            }

            public function getRegionId()
            {
                return $this->id;
            }

            public function setRegionId($regionId)
            {
                $this->id = $regionId;
                return $this;
            }

            public function getExtensionAttributes()
            {
                return null;
            }

            public function setExtensionAttributes(
                \Magento\Customer\Api\Data\RegionExtensionInterface $extensionAttributes
            ) {
                return $this;
            }
        };

        $this->regionFactory->method('create')->willReturn($region);

        return $region;
    }
}
