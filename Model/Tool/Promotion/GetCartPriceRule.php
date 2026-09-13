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
 * Read one cart price rule in full.
 */
class GetCartPriceRule extends AbstractTool
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
        return 'get_cart_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one cart price rule by rule_id: its discount, date window, websites, customer '
            . 'groups and both condition trees. The conditions are read-only here — no tool in '
            . 'this server writes them, so a rule whose conditions need changing has to be edited '
            . 'in the admin.';
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
    public function execute(array $arguments): array
    {
        $ruleId = $this->requireInt($arguments, 'rule_id');

        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No cart price rule exists with rule_id %1.', $ruleId));
        }

        return $this->projector->toDetail($rule);
    }
}
