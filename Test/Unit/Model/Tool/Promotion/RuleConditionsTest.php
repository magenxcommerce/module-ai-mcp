<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\Promotion\RuleConditions;
use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use PHPUnit\Framework\TestCase;

/**
 * Whether a cart price rule restricts anything.
 *
 * The case worth having a test for is the empty root combine. Magento hands
 * back a condition node whether or not anything was put inside it, so the
 * obvious check — is the condition null — reports the exact rules this class
 * exists to catch as conditioned, and a "discount every cart in the store" rule
 * would sail through the activation guard.
 *
 * @see RuleConditions
 */
class RuleConditionsTest extends TestCase
{
    private RuleConditions $conditions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->conditions = new RuleConditions();
    }

    /**
     * @return void
     */
    public function testARuleWithNoConditionNodeAtAllIsUnrestricted(): void
    {
        $this->assertFalse($this->conditions->cartRuleIsRestricted($this->ruleWith(null)));
    }

    /**
     * The one that matters: a node is present, and it restricts nothing.
     *
     * @return void
     */
    public function testAnEmptyRootCombineIsUnrestricted(): void
    {
        $this->assertFalse($this->conditions->cartRuleIsRestricted($this->ruleWith($this->combine([]))));
    }

    /**
     * Magento can also answer with a combine whose `conditions` is null rather
     * than an empty array, depending on how the rule was built.
     *
     * @return void
     */
    public function testACombineWhoseChildrenAreNullIsUnrestricted(): void
    {
        $this->assertFalse($this->conditions->cartRuleIsRestricted($this->ruleWith($this->combine(null))));
    }

    /**
     * @return void
     */
    public function testACombineWithOneChildIsRestricted(): void
    {
        $child = $this->createMock(ConditionInterface::class);

        $this->assertTrue($this->conditions->cartRuleIsRestricted($this->ruleWith($this->combine([$child]))));
    }

    /**
     * Only the root is inspected. A child that is itself an empty combine is
     * still somebody's deliberate rule, and judging its logic is not this
     * class's job — nor is it the difference between "restricts nothing" and
     * "restricts something", which is all the activation guard asks.
     *
     * @return void
     */
    public function testAChildThatIsItselfEmptyStillCountsAsRestricted(): void
    {
        $emptyChild = $this->combine([]);

        $this->assertTrue($this->conditions->cartRuleIsRestricted($this->ruleWith($this->combine([$emptyChild]))));
    }

    /**
     * The two questions are independent: a rule can apply to every cart and
     * still discount only one product, which is an ordinary campaign.
     *
     * @return void
     */
    public function testTheActionConditionIsReportedSeparately(): void
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getCondition')->willReturn($this->combine([]));
        $rule->method('getActionCondition')->willReturn($this->combine([$this->createMock(ConditionInterface::class)]));

        $this->assertFalse($this->conditions->cartRuleIsRestricted($rule));
        $this->assertTrue($this->conditions->cartRuleHasActionCondition($rule));
    }

    /**
     * @param ConditionInterface|null $condition
     * @return RuleInterface
     */
    private function ruleWith(?ConditionInterface $condition): RuleInterface
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getCondition')->willReturn($condition);
        $rule->method('getActionCondition')->willReturn(null);

        return $rule;
    }

    /**
     * @param array<int, ConditionInterface>|null $children
     * @return ConditionInterface
     */
    private function combine(?array $children): ConditionInterface
    {
        $combine = $this->createMock(ConditionInterface::class);
        $combine->method('getConditionType')->willReturn('Magento\\SalesRule\\Model\\Rule\\Condition\\Combine');
        $combine->method('getAggregatorType')->willReturn('all');
        $combine->method('getConditions')->willReturn($children);

        return $combine;
    }
}
