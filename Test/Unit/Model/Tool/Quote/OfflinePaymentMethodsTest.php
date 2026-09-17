<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Quote;

use Magenx\AiMcp\Model\Tool\Quote\OfflinePaymentMethods;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentMethodInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which payment methods this server will let an agent choose.
 *
 * The check is an intersection of two real lists, and the point of the tests is
 * that the two ways of failing it produce different messages — because the
 * fixes are different. An online method is refused for a reason no operator can
 * configure away; an offline one the cart does not offer is refused for a
 * reason an operator can fix in five minutes. Collapsing them into one message
 * would send somebody to the wrong place.
 *
 * @see OfflinePaymentMethods::assertUsable
 */
class OfflinePaymentMethodsTest extends TestCase
{
    private PaymentMethodManagementInterface&MockObject $paymentMethodManagement;
    private OfflinePaymentMethods $methods;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->paymentMethodManagement = $this->createMock(PaymentMethodManagementInterface::class);
        $this->methods = new OfflinePaymentMethods($this->paymentMethodManagement);
    }

    /**
     * The five codes are Magento's own, read off Magento_OfflinePayments and
     * Magento_Payment rather than recalled. Pinned so a sixth is not added
     * without somebody checking it is genuinely offline.
     *
     * @return void
     */
    public function testTheOfflineCodesAreTheFiveMagentoShips(): void
    {
        $this->assertSame(
            ['checkmo', 'banktransfer', 'cashondelivery', 'purchaseorder', 'free'],
            OfflinePaymentMethods::CODES
        );
    }

    /**
     * @return void
     */
    public function testAnOfflineMethodTheCartOffersIsAllowed(): void
    {
        $this->offers(['checkmo' => 'Check / Money order', 'braintree' => 'Credit Card']);

        $this->methods->assertUsable(7, 'checkmo');

        $this->addToAssertionCount(1);
    }

    /**
     * The refusal that has to explain itself, because nothing the operator does
     * will change it.
     *
     * @return void
     */
    public function testAnOnlineMethodIsRefusedForNeedingTheCustomer(): void
    {
        $this->offers(['checkmo' => 'Check / Money order', 'braintree' => 'Credit Card']);

        try {
            $this->methods->assertUsable(7, 'braintree');
            $this->fail('An online method must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('needs the customer at a payment page', $e->getMessage());
            // And it still says what CAN be used, so the agent has a next move.
            $this->assertStringContainsString('checkmo', $e->getMessage());
        }
    }

    /**
     * The other refusal: offline, but this cart cannot have it. A different
     * problem with a different fix, so a different message.
     *
     * @return void
     */
    public function testAnOfflineMethodTheCartDoesNotOfferIsRefusedAsUnavailable(): void
    {
        $this->offers(['checkmo' => 'Check / Money order']);

        try {
            $this->methods->assertUsable(7, 'cashondelivery');
            $this->fail('An unavailable offline method must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('switched off, restricted', $e->getMessage());
            $this->assertStringNotContainsString('needs the customer', $e->getMessage());
        }
    }

    /**
     * A cart with no offline method at all is the case where "which one then?"
     * has no answer, so the message says so rather than trailing off after a
     * colon.
     *
     * @return void
     */
    public function testACartWithNoOfflineMethodSaysSoPlainly(): void
    {
        $this->offers(['braintree' => 'Credit Card']);

        try {
            $this->methods->assertUsable(7, 'checkmo');
            $this->fail('An unavailable offline method must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('none at all', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testOnlyTheOfflineMethodsAreReportedAsAvailable(): void
    {
        $this->offers([
            'checkmo' => 'Check / Money order',
            'free' => 'No Payment Information Required',
            'braintree' => 'Credit Card',
            'paypal_express' => 'PayPal',
        ]);

        $this->assertSame(
            ['checkmo' => 'Check / Money order', 'free' => 'No Payment Information Required'],
            $this->methods->availableFor(7)
        );
    }

    /**
     * @param array<string, string> $codes
     * @return void
     */
    private function offers(array $codes): void
    {
        $methods = [];
        foreach ($codes as $code => $title) {
            $method = $this->createMock(PaymentMethodInterface::class);
            $method->method('getCode')->willReturn($code);
            $method->method('getTitle')->willReturn($title);
            $methods[] = $method;
        }

        $this->paymentMethodManagement->method('getList')->willReturn($methods);
    }
}
