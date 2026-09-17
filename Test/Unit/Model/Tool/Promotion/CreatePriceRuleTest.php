<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\CartPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\CatalogPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\CreateCartPriceRule;
use Magenx\AiMcp\Model\Tool\Promotion\CreateCatalogPriceRule;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory as CatalogRuleFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\SalesRule\Api\Data\RuleInterfaceFactory as CartRuleFactory;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Creating price rules, which always come out switched off.
 *
 * The design these tests pin is unusual enough to be mistaken for an omission:
 * `is_active` is not merely defaulted to false, it is absent from both schemas.
 * That is because no tool in this server writes rule conditions — Magento
 * rebuilds a rule from its data object on save, so anything assembled here
 * stores with none — and an unconditioned rule discounts every cart or
 * re-prices the whole catalogue. A rule that cannot be given conditions must
 * not be given a way to go live either.
 *
 * Somebody "tidying" `is_active` back into the schema would reopen exactly that
 * hole, with the update tools' activation guard as the only thing left between
 * an agent and a store-wide discount. Hence a test per tool saying so.
 *
 * @see CreateCartPriceRule
 * @see CreateCatalogPriceRule
 */
class CreatePriceRuleTest extends TestCase
{
    private CartRuleFactory&MockObject $cartRuleFactory;
    private CatalogRuleFactory&MockObject $catalogRuleFactory;
    private RuleRepositoryInterface&MockObject $ruleRepository;
    private CatalogRuleRepositoryInterface&MockObject $catalogRuleRepository;
    private CreateCartPriceRule $cartTool;
    private CreateCatalogPriceRule $catalogTool;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        GeneratedFactory::ensure(CartRuleFactory::class);
        GeneratedFactory::ensure(CatalogRuleFactory::class);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->cartRuleFactory = $this->createMock(CartRuleFactory::class);
        $this->catalogRuleFactory = $this->createMock(CatalogRuleFactory::class);
        $this->ruleRepository = $this->createMock(RuleRepositoryInterface::class);
        $this->catalogRuleRepository = $this->createMock(CatalogRuleRepositoryInterface::class);

        $cartProjector = $this->createMock(CartPriceRuleProjector::class);
        $cartProjector->method('toSummary')->willReturn([]);
        $catalogProjector = $this->createMock(CatalogPriceRuleProjector::class);
        $catalogProjector->method('toArray')->willReturn([]);

        $this->cartTool = new CreateCartPriceRule(
            $this->cartRuleFactory,
            $this->ruleRepository,
            $cartProjector
        );
        $this->catalogTool = new CreateCatalogPriceRule(
            $this->catalogRuleFactory,
            $this->catalogRuleRepository,
            $catalogProjector
        );
    }

    /**
     * @return void
     */
    public function testBothAreWritesBehindTheirOwnResources(): void
    {
        $this->assertTrue($this->cartTool->isWrite());
        $this->assertSame('Magento_SalesRule::quote', $this->cartTool->getAclResource());

        $this->assertTrue($this->catalogTool->isWrite());
        $this->assertSame('Magento_CatalogRule::promo_catalog', $this->catalogTool->getAclResource());
    }

    /**
     * The claim that must stay true.
     *
     * @return void
     */
    public function testNeitherSchemaOffersIsActive(): void
    {
        foreach ([$this->cartTool->getInputSchema(), $this->catalogTool->getInputSchema()] as $schema) {
            $this->assertArrayNotHasKey('is_active', $schema['properties']);
            $this->assertFalse($schema['additionalProperties']);
        }
    }

    /**
     * @return void
     */
    public function testACartRuleIsSavedInactive(): void
    {
        $rule = $this->createMock(\Magento\SalesRule\Api\Data\RuleInterface::class);
        $rule->expects($this->once())->method('setIsActive')->with(false);
        $this->cartRuleFactory->method('create')->willReturn($rule);
        $this->ruleRepository->method('save')->willReturn($rule);

        $result = $this->cartTool->execute($this->cartArguments());

        $this->assertTrue($result['created']);
        $this->assertFalse($result['is_active']);
        $this->assertStringContainsString('inactive', $result['next_step']);
    }

    /**
     * @return void
     */
    public function testACatalogRuleIsSavedInactive(): void
    {
        $rule = $this->createMock(\Magento\CatalogRule\Api\Data\RuleInterface::class);
        $rule->expects($this->once())->method('setIsActive')->with(false);
        $this->catalogRuleFactory->method('create')->willReturn($rule);
        $this->catalogRuleRepository->method('save')->willReturn($rule);

        $result = $this->catalogTool->execute($this->catalogArguments());

        $this->assertFalse($result['is_active']);
    }

    /**
     * An empty website or customer-group list is not "applies everywhere" — it
     * is a rule that applies nowhere, saved without complaint, which is the
     * kind of quiet nothing this server refuses.
     *
     * @return void
     */
    public function testAnEmptyWebsiteListIsRefused(): void
    {
        $this->cartRuleFactory->method('create')
            ->willReturn($this->createMock(\Magento\SalesRule\Api\Data\RuleInterface::class));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a non-empty array of ids');

        $this->cartTool->execute(['website_ids' => []] + $this->cartArguments());
    }

    /**
     * @return void
     */
    public function testAnUnknownSimpleActionNamesTheValidOnes(): void
    {
        $this->cartRuleFactory->method('create')
            ->willReturn($this->createMock(\Magento\SalesRule\Api\Data\RuleInterface::class));

        try {
            $this->cartTool->execute(['simple_action' => 'half_off'] + $this->cartArguments());
            $this->fail('An unknown simple_action must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('by_percent', $e->getMessage());
            $this->assertStringContainsString('cart_fixed', $e->getMessage());
        }
    }

    /**
     * The catalog rule's actions are a different set from the cart rule's, and
     * passing one where the other belongs would save a rule that prices
     * nothing the way the caller meant.
     *
     * @return void
     */
    public function testTheCatalogActionsAreTheCatalogOnes(): void
    {
        $this->catalogRuleFactory->method('create')
            ->willReturn($this->createMock(\Magento\CatalogRule\Api\Data\RuleInterface::class));

        try {
            $this->catalogTool->execute(['simple_action' => 'cart_fixed'] + $this->catalogArguments());
            $this->fail('A cart rule action must be refused on a catalog rule.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('to_percent', $e->getMessage());
            $this->assertStringNotContainsString('cart_fixed,', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testANegativeDiscountIsRefused(): void
    {
        $this->cartRuleFactory->method('create')
            ->willReturn($this->createMock(\Magento\SalesRule\Api\Data\RuleInterface::class));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cannot be negative');

        $this->cartTool->execute(['discount_amount' => -5] + $this->cartArguments());
    }

    /**
     * @return array<string, mixed>
     */
    private function cartArguments(): array
    {
        return [
            'name' => 'Autumn sale',
            'simple_action' => 'by_percent',
            'discount_amount' => 10,
            'website_ids' => [1],
            'customer_group_ids' => [0, 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogArguments(): array
    {
        return [
            'name' => 'Clearance',
            'simple_action' => 'by_percent',
            'discount_amount' => 15,
            'website_ids' => [1],
            'customer_group_ids' => [0, 1],
        ];
    }
}
