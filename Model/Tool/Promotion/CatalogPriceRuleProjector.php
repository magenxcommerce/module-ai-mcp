<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magento\CatalogRule\Api\Data\RuleInterface;

/**
 * Presents a catalog price rule.
 *
 * Narrower than the cart rule projection because the contract is narrower:
 * Magento exposes a catalog rule's condition only as an opaque serialized
 * blob, so it is reported as present or absent rather than as a readable tree.
 */
class CatalogPriceRuleProjector
{
    /**
     * @param RuleInterface $rule
     * @return array<string, mixed>
     */
    public function toArray(RuleInterface $rule): array
    {
        return [
            'rule_id' => (int) $rule->getRuleId(),
            'name' => $rule->getName(),
            'description' => $rule->getDescription(),
            'is_active' => (bool) $rule->getIsActive(),
            'simple_action' => $rule->getSimpleAction(),
            'discount_amount' => $rule->getDiscountAmount() === null
                ? null
                : (float) $rule->getDiscountAmount(),
            'sort_order' => $rule->getSortOrder() === null ? null : (int) $rule->getSortOrder(),
            'stop_rules_processing' => (bool) $rule->getStopRulesProcessing(),
            // The condition itself is not readable through the API. A rule with
            // none applies to every product, which is worth knowing.
            'has_condition' => $rule->getRuleCondition() !== null,
        ];
    }
}
