<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\CartPriceRuleProjector;
use Magenx\AiMcp\Model\Tool\Promotion\RuleConditions;
use Magenx\AiMcp\Model\Tool\Promotion\UpdateCartPriceRule;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Activating a cart price rule.
 *
 * Every other field this tool writes is reversible by calling it again. This
 * one is not, in the way that matters: a rule with no conditions discounts
 * every cart the moment it goes live, and by the time anyone notices the orders
 * have been placed at the discounted price. Nothing about the request looks
 * wrong — one boolean, a clean save.
 *
 * So the tests here are almost entirely about the refusal, and about the two
 * cases that must NOT be refused: a conditioned rule going live, and an
 * unconditioned rule that is already live being edited, which is somebody's
 * deliberate store-wide promotion rather than an accident to prevent.
 *
 * @see UpdateCartPriceRule::execute
 */
class UpdateCartPriceRuleTest extends TestCase
{
    private RuleRepositoryInterface&MockObject $ruleRepository;
    private UpdateCartPriceRule $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->ruleRepository = $this->createMock(RuleRepositoryInterface::class);

        $projector = $this->createMock(CartPriceRuleProjector::class);
        $projector->method('toSummary')->willReturn([]);

        $this->tool = new UpdateCartPriceRule(
            $this->ruleRepository,
            new RuleConditions(),
            $projector
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheSalesRuleResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_SalesRule::quote', $this->tool->getAclResource());
    }

    /**
     * Writing named fields to the values given, so a repeat lands in the same
     * place, and removing nothing.
     *
     * @return void
     */
    public function testItAdvertisesItselfAsIdempotentAndNotDestructive(): void
    {
        $annotations = $this->tool->getAnnotations();

        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['destructiveHint']);
        $this->assertFalse($annotations['readOnlyHint']);
    }

    /**
     * The guard, and the reason this test file exists.
     *
     * @return void
     */
    public function testActivatingARuleWithNoConditionsIsRefusedAndNothingIsSaved(): void
    {
        $this->ruleRepository->method('getById')->willReturn($this->rule(4, null));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Rule 4 has no conditions');

        $this->tool->execute(['rule_id' => 4, 'is_active' => true]);
    }

    /**
     * The empty root combine — a node is there, and it restricts nothing. This
     * is the shape a rule created through the API actually has, so it is the
     * shape the guard has to catch.
     *
     * @return void
     */
    public function testAnEmptyRootCombineIsRefusedJustLikeNoConditionAtAll(): void
    {
        $this->ruleRepository->method('getById')->willReturn($this->rule(4, $this->combine([])));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('discount every cart in the store');

        $this->tool->execute(['rule_id' => 4, 'is_active' => true]);
    }

    /**
     * @return void
     */
    public function testTheRefusalSaysWhereConditionsCanBeSet(): void
    {
        $this->ruleRepository->method('getById')->willReturn($this->rule(4, null));

        try {
            $this->tool->execute(['rule_id' => 4, 'is_active' => true]);
            $this->fail('Activating a conditionless rule must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('add them in the admin', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testActivatingAConditionedRuleIsAllowed(): void
    {
        $condition = $this->combine([$this->createMock(ConditionInterface::class)]);
        $rule = $this->rule(4, $condition);

        $this->ruleRepository->method('getById')->willReturn($rule);
        $this->ruleRepository->expects($this->once())->method('save')->with($rule)->willReturn($rule);

        $result = $this->tool->execute(['rule_id' => 4, 'is_active' => true]);

        $this->assertTrue($result['updated']);
        $this->assertSame(['is_active'], $result['changed_fields']);
    }

    /**
     * Deactivating is always safe, whatever the rule's conditions are — and
     * it is the way out of a rule that should never have been switched on.
     *
     * @return void
     */
    public function testDeactivatingAConditionlessRuleIsAllowed(): void
    {
        $rule = $this->rule(4, null);
        $this->ruleRepository->method('getById')->willReturn($rule);
        $this->ruleRepository->expects($this->once())->method('save')->willReturn($rule);

        $this->tool->execute(['rule_id' => 4, 'is_active' => false]);
    }

    /**
     * An unconditioned rule that is already live is somebody's deliberate
     * store-wide promotion. Renaming it must not be refused: the guard exists
     * to stop a rule going live, not to quarantine one that already is.
     *
     * @return void
     */
    public function testAnAlreadyLiveUnconditionedRuleCanStillBeEdited(): void
    {
        $rule = $this->rule(4, null);
        $rule->method('getIsActive')->willReturn(true);

        $this->ruleRepository->method('getById')->willReturn($rule);
        $this->ruleRepository->expects($this->once())->method('save')->willReturn($rule);

        $result = $this->tool->execute(['rule_id' => 4, 'name' => 'Renamed']);

        $this->assertSame(['name'], $result['changed_fields']);
    }

    /**
     * @return void
     */
    public function testAnUnknownRuleIsRefusedByIdRatherThanByException(): void
    {
        $this->ruleRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No cart price rule exists with rule_id 99');

        $this->tool->execute(['rule_id' => 99, 'name' => 'Anything']);
    }

    /**
     * @return void
     */
    public function testAnUpdateWithNoFieldsIsRefused(): void
    {
        $this->ruleRepository->method('getById')->willReturn($this->rule(4, null));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Nothing to update');

        $this->tool->execute(['rule_id' => 4]);
    }

    /**
     * A string where a boolean belongs would otherwise activate the rule:
     * "false" is truthy.
     *
     * @return void
     */
    public function testAStringInsteadOfABooleanIsRefused(): void
    {
        $this->ruleRepository->method('getById')->willReturn($this->rule(4, null));
        $this->ruleRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->tool->execute(['rule_id' => 4, 'is_active' => 'true']);
    }

    /**
     * @param int $ruleId
     * @param ConditionInterface|null $condition
     * @return RuleInterface&MockObject
     */
    private function rule(int $ruleId, ?ConditionInterface $condition): RuleInterface&MockObject
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getRuleId')->willReturn($ruleId);
        $rule->method('getCondition')->willReturn($condition);
        $rule->method('getActionCondition')->willReturn(null);

        return $rule;
    }

    /**
     * @param array<int, ConditionInterface> $children
     * @return ConditionInterface
     */
    private function combine(array $children): ConditionInterface
    {
        $combine = $this->createMock(ConditionInterface::class);
        $combine->method('getConditions')->willReturn($children);

        return $combine;
    }
}
