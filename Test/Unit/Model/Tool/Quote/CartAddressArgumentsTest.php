<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\Quote\CartAddressArguments;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\Address;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use PHPUnit\Framework\TestCase;

/**
 * Building the addresses a cart is checked out against.
 *
 * What this class does NOT do is as deliberate as what it does, and the tests
 * pin both. It checks shape and leaves completeness to Magento, because which
 * fields an address must carry depends on the country and the store — policing
 * that here would refuse addresses the store would have accepted, and the rules
 * would drift from Magento's the moment either changed.
 *
 * @see CartAddressArguments::build
 */
class CartAddressArgumentsTest extends TestCase
{
    private CartAddressArguments $arguments;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $factory = $this->createMock(AddressInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): AddressInterface => $this->address()
        );

        $this->arguments = new CartAddressArguments($factory);
    }

    /**
     * The decision worth pinning: an address with almost nothing on it is
     * built, not refused. Magento decides what is missing, per country.
     *
     * @return void
     */
    public function testAnIncompleteAddressIsNotRefusedHere(): void
    {
        $address = $this->arguments->build(['firstname' => 'Ada'], 'billing');

        $this->assertSame('Ada', $address->getFirstname());
        $this->assertNull($address->getCountryId());
    }

    /**
     * @return void
     */
    public function testEveryPlainFieldIsApplied(): void
    {
        $address = $this->arguments->build([
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'city' => 'London',
            'postcode' => 'NW1',
            'country_id' => 'GB',
            'telephone' => '0100',
            'email' => 'ada@example.com',
        ], 'billing');

        $this->assertSame('Lovelace', $address->getLastname());
        $this->assertSame('GB', $address->getCountryId());
        // A guest cart has nowhere else to put an e-mail, which is why a quote
        // address carries one and a customer address does not.
        $this->assertSame('ada@example.com', $address->getEmail());
    }

    /**
     * @return void
     */
    public function testASingleStreetStringIsAcceptedAsOneLine(): void
    {
        $address = $this->arguments->build(['street' => '1 Main St'], 'shipping');

        $this->assertSame(['1 Main St'], $address->getStreet());
    }

    /**
     * @return void
     */
    public function testStreetLinesSurviveAsAList(): void
    {
        $address = $this->arguments->build(['street' => ['1 Main St', 'Flat 2']], 'shipping');

        $this->assertSame(['1 Main St', 'Flat 2'], $address->getStreet());
    }

    /**
     * Shape, not completeness: a street that is not lines would fail as a bad
     * save rather than as a readable message.
     *
     * @return void
     */
    public function testAStreetThatIsNeitherALineNorAListIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a line or a list of lines');

        $this->arguments->build(['street' => 42], 'shipping');
    }

    /**
     * An empty list is not an address with no street — it is an argument that
     * says nothing, and storing it would drop the street silently.
     *
     * @return void
     */
    public function testAnEmptyStreetListIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a line or a list of lines');

        $this->arguments->build(['street' => []], 'billing');
    }

    /**
     * @return void
     */
    public function testANonStringStreetLineIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a string');

        $this->arguments->build(['street' => [42]], 'billing');
    }

    /**
     * The message names which address failed, because set_cart_delivery builds
     * two of them in one call and "the address is wrong" would not say which.
     *
     * @return void
     */
    public function testTheMessageNamesWhichAddressFailed(): void
    {
        try {
            $this->arguments->build(['city' => 42], 'shipping');
            $this->fail('A non-string field must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('shipping address field "city"', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testANonNumericRegionIdIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"region_id" must be a whole number');

        $this->arguments->build(['region_id' => 'California'], 'billing');
    }

    /**
     * @return void
     */
    public function testTheSchemaIsAClosedObject(): void
    {
        $schema = $this->arguments->schemaProperties();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('email', $schema['properties']);
    }

    /**
     * A quote address that actually remembers what was set on it.
     *
     * A mock cannot: these tests are about the values that end up on the
     * address, so the harness carries a concrete stand-in.
     *
     * @return AddressInterface
     */
    private function address(): AddressInterface
    {
        return new Address();
    }
}
