<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\Customer\CustomerProfileArguments;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The profile fields shared by create_customer and update_customer.
 *
 * @see CustomerProfileArguments
 */
class CustomerProfileArgumentsTest extends TestCase
{
    private CustomerProfileArguments $profile;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->profile = new CustomerProfileArguments();
    }

    /**
     * An update must leave alone every field the caller did not mention;
     * writing a null over an unmentioned field is how a profile loses its date
     * of birth on an unrelated edit.
     *
     * @return void
     */
    public function testOnlyThePassedFieldsAreSet(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->once())->method('setFirstname')->with('Ada');
        $customer->expects($this->once())->method('setLastname')->with('Lovelace');
        $customer->expects($this->never())->method('setDob');
        $customer->expects($this->never())->method('setGroupId');
        $customer->expects($this->never())->method('setTaxvat');

        $changed = $this->profile->applyTo($customer, ['firstname' => 'Ada', 'lastname' => 'Lovelace']);

        $this->assertSame(['firstname', 'lastname'], $changed);
    }

    /**
     * @return void
     */
    public function testNumericFieldsAreCastToIntegers(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        // A JSON round-trip turns 2 into "2"; the group id Magento validates
        // against must still be an integer.
        $customer->expects($this->once())->method('setGroupId')->with(2);
        $customer->expects($this->once())->method('setStoreId')->with(3);
        $customer->expects($this->once())->method('setGender')->with(1);

        $changed = $this->profile->applyTo($customer, ['group_id' => '2', 'store_id' => 3, 'gender' => 1]);

        $this->assertSame(['group_id', 'store_id', 'gender'], $changed);
    }

    /**
     * @return void
     */
    public function testFlagIsStoredAsMagentosIntegerForm(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->once())->method('setDisableAutoGroupChange')->with(1);

        $changed = $this->profile->applyTo($customer, ['disable_auto_group_change' => true]);

        $this->assertSame(['disable_auto_group_change'], $changed);
    }

    /**
     * @return void
     */
    public function testFalseFlagIsStoredRatherThanSkipped(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->once())->method('setDisableAutoGroupChange')->with(0);

        $this->profile->applyTo($customer, ['disable_auto_group_change' => false]);
    }

    /**
     * @return void
     */
    public function testCustomAttributesArePassedThroughAndReported(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->once())->method('setCustomAttribute')->with('loyalty_tier', 'gold');

        $changed = $this->profile->applyTo($customer, [
            'custom_attributes' => ['loyalty_tier' => 'gold'],
        ]);

        $this->assertSame(['loyalty_tier'], $changed);
    }

    /**
     * A key that cannot name an attribute is skipped rather than being sent to
     * Magento, where it would fail the whole save.
     *
     * @return void
     */
    public function testUnusableCustomAttributeKeysAreSkipped(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->once())->method('setCustomAttribute')->with('loyalty_tier', 'gold');

        $changed = $this->profile->applyTo($customer, [
            'custom_attributes' => ['loyalty_tier' => 'gold', '' => 'blank key'],
        ]);

        $this->assertSame(['loyalty_tier'], $changed);
    }

    /**
     * @return void
     */
    public function testNonObjectCustomAttributesIsIgnored(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects($this->never())->method('setCustomAttribute');

        $this->assertSame([], $this->profile->applyTo($customer, ['custom_attributes' => 'nope']));
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

        $this->profile->applyTo($this->createMock(CustomerInterface::class), $arguments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedArgumentsProvider(): array
    {
        return [
            'group id is a label' => [
                ['group_id' => 'wholesale'],
                'The "group_id" argument must be a whole number.',
            ],
            // (int) true is 1, which would quietly assign group 1.
            'group id is a boolean' => [
                ['group_id' => true],
                'The "group_id" argument must be a whole number.',
            ],
            'gender is a label' => [
                ['gender' => 'female'],
                'The "gender" argument must be a whole number.',
            ],
            'name given an array' => [
                ['firstname' => ['Ada']],
                'The "firstname" argument must be a string.',
            ],
            'flag given a string' => [
                ['disable_auto_group_change' => 'yes'],
                'must be true or false',
            ],
        ];
    }

    /**
     * An empty result is what update_customer turns into "nothing to update"
     * rather than an empty save.
     *
     * @return void
     */
    public function testIdentifierOnlyArgumentsChangeNothing(): void
    {
        $changed = $this->profile->applyTo(
            $this->createMock(CustomerInterface::class),
            ['customer_id' => 5, 'email' => 'a@b.test']
        );

        $this->assertSame([], $changed);
    }
}
