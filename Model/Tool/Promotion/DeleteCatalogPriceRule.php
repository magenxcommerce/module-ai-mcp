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
 * Delete a catalog price rule.
 *
 * Loaded before it is deleted so the result can name the rule rather than echo
 * an id, and so an unknown id answers with a message instead of a repository
 * exception — the same shape as {@see DeleteCartPriceRule}.
 *
 * The difference worth reporting is the timing. Deleting an active catalog rule
 * does not restore prices at the moment of the call: the rule's discounted
 * prices stay in the index until the catalog rule indexer runs again. So the
 * result flags `reindex_required`, exactly as the update tool does, and
 * apply_catalog_price_rules is the way to ask for that run.
 */
class DeleteCatalogPriceRule extends AbstractTool
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
        return 'delete_catalog_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a catalog price rule permanently. This cannot be undone. Prices do not go '
            . 'back to normal at the moment of the call — the rule\'s prices stay in the index '
            . 'until the catalog rule indexer runs, which apply_catalog_price_rules schedules. To '
            . 'stop a rule reversibly, set is_active false with update_catalog_price_rule instead.';
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
            $rule = $this->catalogRuleRepository->get($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No catalog price rule exists with rule_id %1.', $ruleId));
        }

        // Read the projection while the rule still exists.
        $summary = $this->projector->toArray($rule);

        $this->catalogRuleRepository->deleteById($ruleId);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'reindex_required' => true,
            'note' => 'Prices this rule set stay in the index until the catalog rule indexer runs '
                . 'again; apply_catalog_price_rules schedules that.',
        ] + $summary;
    }
}
