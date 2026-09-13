<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read one catalog price rule.
 */
class GetCatalogPriceRule extends AbstractTool
{
    /**
     * @param CatalogRuleRepositoryInterface $catalogRuleRepository
     * @param CatalogPriceRuleProjector $projector
     */
    public function __construct(
        private readonly CatalogRuleRepositoryInterface $catalogRuleRepository,
        private readonly CatalogPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_catalog_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one catalog price rule by rule_id. A catalog price rule changes product '
            . 'prices themselves, before anything reaches the cart. Its matching conditions are '
            . 'not readable through Magento\'s API, so this reports only whether it has any.';
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
                    'description' => 'Id of the rule, as search_catalog_price_rules reports it.',
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
        return 'Magento_CatalogRule::promo_catalog';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $ruleId = $this->requireInt($arguments, 'rule_id');

        try {
            $rule = $this->catalogRuleRepository->get($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No catalog price rule exists with rule_id %1.', $ruleId));
        }

        return $this->projector->toArray($rule);
    }
}
