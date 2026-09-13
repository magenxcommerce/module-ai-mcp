<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\Sales\LineItemArguments;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validation of the `items` argument shared by the invoice, shipment and credit
 * memo tools.
 *
 * Magento reads an empty item list as "the whole order", so a malformed entry
 * must be an error rather than a discarded one — on a credit memo that is the
 * difference between refunding one line and refunding everything.
 *
 * @see LineItemArguments
 */
class LineItemArgumentsTest extends TestCase
{
    private LineItemArguments $lineItems;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->lineItems = new LineItemArguments();
    }

    /**
     * @return void
     */
    public function testNoItemsNormalisesToAnEmptyList(): void
    {
        $this->assertSame([], $this->lineItems->normalize([]));
    }

    /**
     * @return void
     */
    public function testItemsAreKeyedByOrderItemId(): void
    {
        $this->assertSame(
            [12 => 2.0, 15 => 1.5],
            $this->lineItems->normalize([
                ['order_item_id' => 12, 'qty' => 2],
                ['order_item_id' => 15, 'qty' => 1.5],
            ])
        );
    }

    /**
     * A model that has been round-tripped through JSON, or that is simply
     * imprecise, sends "3" where 3 was meant. Accepting the numeric string is
     * safe; the qty still has to be a number.
     *
     * @return void
     */
    public function testNumericStringsAreAccepted(): void
    {
        $this->assertSame(
            [7 => 3.0],
            $this->lineItems->normalize([['order_item_id' => '7', 'qty' => '3']])
        );
    }

    /**
     * @param array<mixed> $items
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('rejectedItemsProvider')]
    public function testMalformedItemsAreRefused(array $items, string $expectedMessage): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->lineItems->normalize($items);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public static function rejectedItemsProvider(): array
    {
        return [
            'item is not an object' => [
                [42],
                'Item 1 must be an object with order_item_id and qty.',
            ],
            'missing order_item_id' => [
                [['qty' => 1]],
                'Item 1 needs an "order_item_id". get_order lists it for every line.',
            ],
            'non-numeric order_item_id' => [
                [['order_item_id' => 'abc', 'qty' => 1]],
                'Item 1 needs an "order_item_id". get_order lists it for every line.',
            ],
            'missing qty' => [
                [['order_item_id' => 3]],
                'Item 1 needs a numeric "qty".',
            ],
            'non-numeric qty' => [
                [['order_item_id' => 3, 'qty' => 'two']],
                'Item 1 needs a numeric "qty".',
            ],
            'zero qty' => [
                [['order_item_id' => 3, 'qty' => 0]],
                'a quantity must be greater than zero',
            ],
            'negative qty' => [
                [['order_item_id' => 3, 'qty' => -1]],
                'a quantity must be greater than zero',
            ],
            'duplicate order_item_id' => [
                [['order_item_id' => 3, 'qty' => 1], ['order_item_id' => 3, 'qty' => 2]],
                'order_item_id 3 appears more than once; give it one combined qty.',
            ],
        ];
    }

    /**
     * The index in the message is the caller's position, not the array key, so
     * it matches what the agent sent.
     *
     * @return void
     */
    public function testTheRefusalNamesThePositionOfTheBadItem(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Item 2 needs a numeric "qty".');

        $this->lineItems->normalize([
            ['order_item_id' => 1, 'qty' => 1],
            ['order_item_id' => 2],
        ]);
    }
}
