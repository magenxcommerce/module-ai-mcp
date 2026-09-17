<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magento\SalesRule\Api\Data\ConditionInterface;
use Magento\SalesRule\Api\Data\RuleInterface;

/**
 * Whether a price rule actually restricts anything.
 *
 * This exists because "no conditions" is the dangerous state and it does not
 * look like one. A cart rule with no conditions discounts every cart; a catalog
 * rule with none re-prices the whole catalogue. Neither reads as broken —
 * Magento saves and applies them happily.
 *
 * The test is not `getCondition() === null`. Magento gives every rule a root
 * *combine* node whether or not anything was put inside it, so an unconditioned
 * rule still answers with an object — one carrying a `condition_type` and an
 * `aggregator_type` and no children at all. Checking for null would therefore
 * report the exact rules this class exists to catch as conditioned.
 *
 * Only the root is inspected. A combine with children is restricting something,
 * whatever those children are; walking further would be judging the rule's
 * logic rather than its existence, which is not this server's business.
 */
class RuleConditions
{
    /**
     * Whether a cart price rule restricts which carts it applies to.
     *
     * @param RuleInterface $rule
     * @return bool
     */
    public function cartRuleIsRestricted(RuleInterface $rule): bool
    {
        return $this->hasChildren($rule->getCondition());
    }

    /**
     * Whether a cart price rule restricts which *items* it discounts.
     *
     * Reported separately because the two are independent: a rule can apply to
     * every cart and still discount only one product, which is a normal
     * campaign rather than the runaway case.
     *
     * @param RuleInterface $rule
     * @return bool
     */
    public function cartRuleHasActionCondition(RuleInterface $rule): bool
    {
        return $this->hasChildren($rule->getActionCondition());
    }

    /**
     * @param ConditionInterface|null $condition
     * @return bool
     */
    private function hasChildren(?ConditionInterface $condition): bool
    {
        if ($condition === null) {
            return false;
        }

        $children = $condition->getConditions();

        return is_array($children) && $children !== [];
    }
}
