<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\RuleInterface;

/**
 * Presents a cart price rule.
 *
 * The condition trees are included in the detail view because they are the
 * usual answer to "why is this rule not applying", but only as a reading: none
 * of the tools here write conditions, and the projection is depth-limited so a
 * deeply nested rule cannot fill the response.
 */
class CartPriceRuleProjector
{
    /** Deep enough for any hand-built rule; guards against a pathological tree. */
    private const MAX_CONDITION_DEPTH = 6;

    /**
     * @param RuleInterface $rule
     * @return array<string, mixed>
     */
    public function toSummary(RuleInterface $rule): array
    {
        return [
            'rule_id' => (int) $rule->getRuleId(),
            'name' => $rule->getName(),
            'is_active' => (bool) $rule->getIsActive(),
            'coupon_type' => $rule->getCouponType(),
            'simple_action' => $rule->getSimpleAction(),
            'discount_amount' => $rule->getDiscountAmount() === null
                ? null
                : (float) $rule->getDiscountAmount(),
            'from_date' => $rule->getFromDate(),
            'to_date' => $rule->getToDate(),
            'sort_order' => $rule->getSortOrder() === null ? null : (int) $rule->getSortOrder(),
            'times_used' => $rule->getTimesUsed() === null ? null : (int) $rule->getTimesUsed(),
        ];
    }

    /**
     * @param RuleInterface $rule
     * @return array<string, mixed>
     */
    public function toDetail(RuleInterface $rule): array
    {
        $detail = $this->toSummary($rule);
        $detail['description'] = $rule->getDescription();
        $detail['website_ids'] = array_map('intval', $rule->getWebsiteIds() ?? []);
        $detail['customer_group_ids'] = array_map('intval', $rule->getCustomerGroupIds() ?? []);
        $detail['uses_per_customer'] = $rule->getUsesPerCustomer() === null
            ? null
            : (int) $rule->getUsesPerCustomer();
        $detail['uses_per_coupon'] = $rule->getUsesPerCoupon() === null
            ? null
            : (int) $rule->getUsesPerCoupon();
        $detail['discount_qty'] = $rule->getDiscountQty() === null ? null : (float) $rule->getDiscountQty();
        $detail['discount_step'] = $rule->getDiscountStep() === null ? null : (int) $rule->getDiscountStep();
        $detail['apply_to_shipping'] = (bool) $rule->getApplyToShipping();
        $detail['stop_rules_processing'] = (bool) $rule->getStopRulesProcessing();
        $detail['is_advanced'] = (bool) $rule->getIsAdvanced();
        $detail['use_auto_generation'] = (bool) $rule->getUseAutoGeneration();
        $detail['condition'] = $this->condition($rule->getCondition(), 0);
        $detail['action_condition'] = $this->condition($rule->getActionCondition(), 0);

        return $detail;
    }

    /**
     * One node of a condition tree, and its children.
     *
     * @param ConditionInterface|null $condition
     * @param int $depth
     * @return array<string, mixed>|null
     */
    private function condition(?ConditionInterface $condition, int $depth): ?array
    {
        if ($condition === null) {
            return null;
        }

        if ($depth >= self::MAX_CONDITION_DEPTH) {
            return ['truncated' => true, 'reason' => 'Nested deeper than this view reports.'];
        }

        $node = [];
        foreach ([
            'condition_type' => $condition->getConditionType(),
            'attribute_name' => $condition->getAttributeName(),
            'operator' => $condition->getOperator(),
            'value' => $condition->getValue(),
            'aggregator_type' => $condition->getAggregatorType(),
        ] as $key => $value) {
            // A combine node has no attribute or operator, and a leaf has no
            // aggregator; carrying the nulls doubles the size of a tree for
            // nothing.
            if ($value !== null) {
                $node[$key] = $value;
            }
        }

        $children = $condition->getConditions();
        if (is_array($children) && $children !== []) {
            $node['conditions'] = array_values(array_filter(array_map(
                fn (?ConditionInterface $child): ?array => $this->condition($child, $depth + 1),
                $children
            )));
        }

        return $node;
    }
}
