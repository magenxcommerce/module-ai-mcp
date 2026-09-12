<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Price;

use Magenx\AiMcp\Model\Tool\Price\TierPriceArguments;
use Magento\Catalog\Api\Data\PriceUpdateResultInterface;
use Magento\Catalog\Api\Data\TierPriceInterface;
use Magento\Catalog\Api\Data\TierPriceInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Building and validating tier price rows, and the failure path that decides
 * whether a price change is reported as having happened.
 *
 * @see TierPriceArguments
 */
class TierPriceArgumentsTest extends TestCase
{
    private TierPriceInterfaceFactory&MockObject $tierPriceFactory;

    private TierPriceArguments $tierPrices;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->tierPriceFactory = $this->createMock(TierPriceInterfaceFactory::class);
        $this->tierPriceFactory->method('create')
            ->willReturnCallback(fn (): TierPriceInterface => $this->createMock(TierPriceInterface::class));
        $this->tierPrices = new TierPriceArguments($this->tierPriceFactory);
    }

    /**
     * @return void
     */
    public function testTheDocumentedDefaultsAreApplied(): void
    {
        $row = $this->createMock(TierPriceInterface::class);
        $row->expects($this->once())->method('setSku')->with('ABC');
        $row->expects($this->once())->method('setQuantity')->with(5.0);
        $row->expects($this->once())->method('setPrice')->with(9.99);
        // A tier with no group or website given applies to everyone everywhere,
        // which is what the schema promises.
        $row->expects($this->once())->method('setCustomerGroup')->with(TierPriceArguments::ALL_GROUPS);
        $row->expects($this->once())->method('setWebsiteId')->with(0);
        $row->expects($this->once())->method('setPriceType')->with('fixed');
        $this->onlyRow($row);

        $built = $this->tierPrices->build([['sku' => 'ABC', 'quantity' => 5, 'price' => 9.99]], true);

        $this->assertCount(1, $built);
    }

    /**
     * A delete names a tier; it must not invent a price for it.
     *
     * @return void
     */
    public function testADeleteRowCarriesNoPrice(): void
    {
        $row = $this->createMock(TierPriceInterface::class);
        $row->expects($this->never())->method('setPrice');
        $row->expects($this->never())->method('setPriceType');
        $this->onlyRow($row);

        $this->tierPrices->build([['sku' => 'ABC', 'quantity' => 5]], false);
    }

    /**
     * @param array<mixed> $rows
     * @param bool $requireValue
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('malformedRowsProvider')]
    public function testMalformedRowsAreRefused(array $rows, bool $requireValue, string $expectedMessage): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->tierPrices->build($rows, $requireValue);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: bool, 2: string}>
     */
    public static function malformedRowsProvider(): array
    {
        return [
            'no rows' => [[], true, 'Pass at least one row in "prices".'],
            'row is not an object' => [[5], true, 'Row 1 must be an object.'],
            'missing sku' => [[['quantity' => 1, 'price' => 1]], true, 'Row 1 needs a "sku".'],
            'blank sku' => [[['sku' => '   ', 'quantity' => 1, 'price' => 1]], true, 'Row 1 needs a "sku".'],
            'missing quantity' => [[['sku' => 'A', 'price' => 1]], true, 'Row 1 needs a numeric "quantity".'],
            // Quantity 0 would be a tier that always applies, which is what the
            // product price already is.
            'quantity below one' => [[['sku' => 'A', 'quantity' => 0, 'price' => 1]], true, 'a tier starts at 1'],
            'missing price' => [[['sku' => 'A', 'quantity' => 2]], true, 'Row 1 needs a numeric "price".'],
            'bad price type' => [
                [['sku' => 'A', 'quantity' => 2, 'price' => 1, 'price_type' => 'percent']],
                true,
                'pass "fixed" or "discount"',
            ],
            'bad customer group' => [
                [['sku' => 'A', 'quantity' => 2, 'price' => 1, 'customer_group' => 3]],
                true,
                'unusable "customer_group"',
            ],
            'bad website id' => [
                [['sku' => 'A', 'quantity' => 2, 'price' => 1, 'website_id' => 'all']],
                true,
                'unusable "website_id"',
            ],
        ];
    }

    /**
     * Two rows naming the same tier are two intentions for one price, and
     * Magento would silently keep whichever it applied last.
     *
     * @return void
     */
    public function testTwoRowsForTheSameTierAreRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Rows 1 and 2 both set the tier');

        $this->tierPrices->build([
            ['sku' => 'A', 'quantity' => 5, 'price' => 10],
            // Case differs; it is still the same tier.
            ['sku' => 'a', 'quantity' => 5, 'price' => 8],
        ], true);
    }

    /**
     * @return void
     */
    public function testTiersDifferingOnAnyKeyPartAreBothKept(): void
    {
        $this->assertCount(2, $this->tierPrices->build([
            ['sku' => 'A', 'quantity' => 5, 'price' => 10, 'customer_group' => 'General'],
            ['sku' => 'A', 'quantity' => 5, 'price' => 8, 'customer_group' => 'Wholesale'],
        ], true));
    }

    /**
     * The storage reports rejected rows by returning them rather than throwing,
     * so an empty return is the only thing that means the prices were applied.
     *
     * @return void
     */
    public function testAnEmptyFailureListPassesSilently(): void
    {
        $this->expectNotToPerformAssertions();

        $this->tierPrices->assertNoFailures([], 'update');
    }

    /**
     * @return void
     */
    public function testFailuresBecomeAToolErrorWithTheirPlaceholdersFilled(): void
    {
        $failure = $this->createMock(PriceUpdateResultInterface::class);
        $failure->method('getMessage')->willReturn('Invalid Price = %price for SKU = %SKU.');
        $failure->method('getParameters')->willReturn(['price' => -1, 'SKU' => 'ABC']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid Price = -1 for SKU = ABC.');

        $this->tierPrices->assertNoFailures([$failure], 'update');
    }

    /**
     * Substituting the shorter name first would turn "%priceType" into the
     * price value followed by a stray "Type".
     *
     * @return void
     */
    public function testAPlaceholderThatPrefixesAnotherIsNotEaten(): void
    {
        $failure = $this->createMock(PriceUpdateResultInterface::class);
        $failure->method('getMessage')->willReturn('%price and %priceType');
        $failure->method('getParameters')->willReturn(['price' => '5', 'priceType' => 'fixed']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('5 and fixed');

        $this->tierPrices->assertNoFailures([$failure], 'update');
    }

    /**
     * Make the factory hand out one specific row object.
     *
     * @param TierPriceInterface $row
     * @return void
     */
    private function onlyRow(TierPriceInterface $row): void
    {
        $factory = $this->createMock(TierPriceInterfaceFactory::class);
        $factory->method('create')->willReturn($row);
        $this->tierPrices = new TierPriceArguments($factory);
    }
}
