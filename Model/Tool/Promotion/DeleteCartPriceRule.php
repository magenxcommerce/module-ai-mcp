<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Delete a cart price rule.
 *
 * The rule is loaded before it is deleted, for two reasons. An unknown id
 * answers with a plain message rather than a repository exception, and the
 * result can say what was removed — a confirm preview that echoes only a
 * `rule_id` tells nobody which campaign is about to go.
 *
 * Deleting takes its coupon codes with it, which is the part worth knowing
 * before confirming: any code a customer already holds stops working. Orders
 * already placed with one are unaffected. Deactivating with
 * update_cart_price_rule stops a campaign just as completely and can be undone,
 * so the description points there first.
 */
class DeleteCartPriceRule extends AbstractTool
{
    /**
     * @param RuleRepositoryInterface $ruleRepository
     * @param CartPriceRuleProjector $projector
     */
    public function __construct(
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly CartPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_cart_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a cart price rule permanently, along with every coupon code generated for '
            . 'it. This cannot be undone and any code a customer already holds stops working; '
            . 'orders already placed with one are unaffected. To stop a campaign reversibly, set '
            . 'is_active false with update_cart_price_rule instead.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rule_id' => [
                    'type' => 'integer',
                    'description' => 'Id of the rule, as search_cart_price_rules reports it.',
                ],
            ],
            'required' => ['rule_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_SalesRule::quote';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $ruleId = $this->requireInt($arguments, 'rule_id');

        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No cart price rule exists with rule_id %1.', $ruleId));
        }

        // Read the summary while the rule still exists; after the delete there
        // is nothing left to describe.
        $summary = $this->projector->toSummary($rule);

        $this->ruleRepository->deleteById($ruleId);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'note' => 'Any coupon codes belonging to this rule were deleted with it.',
        ] + $summary;
    }
}
