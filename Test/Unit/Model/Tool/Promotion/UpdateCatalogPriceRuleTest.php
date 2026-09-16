<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\CatalogPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\UpdateCatalogPriceRule;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\ConditionInterface;
use Magento\CatalogRule\Api\Data\RuleInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Activating a catalog price rule.
 *
 * The cart-rule guard's larger sibling: a catalog rule with no condition
 * re-prices the entire catalogue, and unlike a cart discount the damage is
 * still there after the rule is switched off again, until the indexer runs.
 *
 * The check itself is simpler than the cart one. Magento exposes a catalog
 * rule's condition only as an opaque blob, so there is no empty-root-combine
 * case to see through — present or absent is the whole question, and it is
 * asked of a rule loaded through the repository rather than off a collection,
 * where the field is not reliably populated.
 *
 * @see UpdateCatalogPriceRule::execute
 */
class UpdateCatalogPriceRuleTest extends TestCase
{
    private CatalogRuleRepositoryInterface&MockObject $catalogRuleRepository;
    private UpdateCatalogPriceRule $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->catalogRuleRepository = $this->createMock(CatalogRuleRepositoryInterface::class);

        $projector = $this->createMock(CatalogPriceRuleProjector::class);
        $projector->method('toArray')->willReturn([]);

        $this->tool = new UpdateCatalogPriceRule($this->catalogRuleRepository, $projector);
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheCatalogRuleResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_CatalogRule::promo_catalog', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testItAdvertisesItselfAsIdempotentAndNotDestructive(): void
    {
        $annotations = $this->tool->getAnnotations();

        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['destructiveHint']);
    }

    /**
     * @return void
     */
    public function testActivatingARuleWithNoConditionIsRefusedAndNothingIsSaved(): void
    {
        $this->catalogRuleRepository->method('get')->willReturn($this->rule(null));
        $this->catalogRuleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('re-price the entire catalogue');

        $this->tool->execute(['rule_id' => 12, 'is_active' => true]);
    }

    /**
     * @return void
     */
    public function testActivatingAConditionedRuleIsAllowedAndFlagsTheReindex(): void
    {
        $rule = $this->rule($this->createMock(ConditionInterface::class));
        $this->catalogRuleRepository->method('get')->willReturn($rule);
        $this->catalogRuleRepository->expects($this->once())->method('save')->with($rule)->willReturn($rule);

        $result = $this->tool->execute(['rule_id' => 12, 'is_active' => true]);

        $this->assertTrue($result['updated']);
        // Prices do not move until the catalog rule indexer runs, and a caller
        // that does not know that reads a successful save as a price change.
        $this->assertTrue($result['reindex_required']);
    }

    /**
     * @return void
     */
    public function testDeactivatingAConditionlessRuleIsAllowed(): void
    {
        $rule = $this->rule(null);
        $this->catalogRuleRepository->method('get')->willReturn($rule);
        $this->catalogRuleRepository->expects($this->once())->method('save')->willReturn($rule);

        $this->tool->execute(['rule_id' => 12, 'is_active' => false]);
    }

    /**
     * @return void
     */
    public function testAnAlreadyLiveUnconditionedRuleCanStillBeRenamed(): void
    {
        $rule = $this->rule(null);
        $this->catalogRuleRepository->method('get')->willReturn($rule);
        $this->catalogRuleRepository->expects($this->once())->method('save')->willReturn($rule);

        $result = $this->tool->execute(['rule_id' => 12, 'name' => 'Renamed']);

        $this->assertSame(['name'], $result['changed_fields']);
    }

    /**
     * @return void
     */
    public function testAnUnknownRuleIsRefusedByIdRatherThanByException(): void
    {
        $this->catalogRuleRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No catalog price rule exists with rule_id 99');

        $this->tool->execute(['rule_id' => 99, 'name' => 'Anything']);
    }

    /**
     * @return void
     */
    public function testAStringInsteadOfABooleanIsRefused(): void
    {
        $this->catalogRuleRepository->method('get')->willReturn($this->rule(null));
        $this->catalogRuleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->tool->execute(['rule_id' => 12, 'is_active' => 'true']);
    }

    /**
     * @param ConditionInterface|null $condition
     * @return RuleInterface&MockObject
     */
    private function rule(?ConditionInterface $condition): RuleInterface&MockObject
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getRuleId')->willReturn(12);
        $rule->method('getRuleCondition')->willReturn($condition);

        return $rule;
    }
}
