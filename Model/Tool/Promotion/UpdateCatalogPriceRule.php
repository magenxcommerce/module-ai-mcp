<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change a catalog price rule, short of its conditions.
 *
 * The rule is fetched from the repository and mutated in place. That matters
 * more here than anywhere else in this module: a catalog price rule rebuilt
 * without its serialized condition would apply to the entire catalogue, so the
 * loaded rule is the only thing ever saved.
 */
class UpdateCatalogPriceRule extends AbstractTool
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
        return 'update_catalog_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a catalog price rule: activate or deactivate it, rename it, or adjust its '
            . 'discount and priority. Only the fields you pass are changed, and its matching '
            . 'conditions are left untouched. Prices change only once the catalog rule indexer '
            . 'has run, which invalidate_indexers can trigger. Activating a rule re-prices every '
            . 'product it matches.';
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
                'is_active' => ['type' => 'boolean', 'description' => 'Whether the rule applies.'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'simple_action' => [
                    'type' => 'string',
                    'enum' => ['by_percent', 'by_fixed', 'to_percent', 'to_fixed'],
                    'description' => 'How discount_amount is read: by_percent takes that percentage '
                        . 'off, by_fixed takes that amount off, to_percent sets the price to that '
                        . 'percentage of the original, to_fixed sets it to that amount.',
                ],
                'discount_amount' => [
                    'type' => 'number',
                    'description' => 'The amount or percentage, read according to simple_action.',
                ],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Order the rule is applied in, lower first.',
                ],
                'stop_rules_processing' => [
                    'type' => 'boolean',
                    'description' => 'Stop applying any further catalog rule once this one matches.',
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
    protected function isDestructive(): bool
    {
        // Changes an existing rule's fields; removes nothing.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
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

        $changed = $this->apply($rule, $arguments);
        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides rule_id.'));
        }

        // A catalog rule's condition is an opaque blob in Magento's API, so all
        // that can be asked is whether one exists — which is the only thing
        // that matters here. A rule with none re-prices every product.
        if (($arguments['is_active'] ?? false) === true && $rule->getRuleCondition() === null) {
            throw new LocalizedException(__(
                'Rule %1 has no condition, so activating it would re-price the entire catalogue. '
                . 'Conditions cannot be set through this server — add one in the admin and enable '
                . 'it there. Everything else about the rule can still be changed here.',
                $ruleId
            ));
        }

        $saved = $this->catalogRuleRepository->save($rule);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'reindex_required' => true,
        ] + $this->projector->toArray($saved);
    }

    /**
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(RuleInterface $rule, array $arguments): array
    {
        $changed = [];

        foreach (['is_active' => 'setIsActive', 'stop_rules_processing' => 'setStopRulesProcessing'] as $key => $set) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new LocalizedException(
                    __('The "%1" argument must be true or false, not a string or a number.', $key)
                );
            }
            $rule->{$set}($arguments[$key]);
            $changed[] = $key;
        }

        foreach (['name' => 'setName', 'description' => 'setDescription'] as $key => $set) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_string($arguments[$key])) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $rule->{$set}($arguments[$key]);
            $changed[] = $key;
        }

        if (array_key_exists('simple_action', $arguments)) {
            $action = $arguments['simple_action'];
            $allowed = ['by_percent', 'by_fixed', 'to_percent', 'to_fixed'];
            if (!is_string($action) || !in_array($action, $allowed, true)) {
                throw new LocalizedException(__(
                    'The "simple_action" argument must be one of: %1.',
                    implode(', ', $allowed)
                ));
            }
            $rule->setSimpleAction($action);
            $changed[] = 'simple_action';
        }

        if (array_key_exists('discount_amount', $arguments)) {
            $amount = $arguments['discount_amount'];
            if (!is_int($amount) && !is_float($amount) && !(is_string($amount) && is_numeric($amount))) {
                throw new LocalizedException(__('The "discount_amount" argument must be a number.'));
            }
            if ((float) $amount < 0) {
                throw new LocalizedException(__('The "discount_amount" argument cannot be negative.'));
            }
            $rule->setDiscountAmount((float) $amount);
            $changed[] = 'discount_amount';
        }

        if (array_key_exists('sort_order', $arguments)) {
            $sortOrder = $arguments['sort_order'];
            if (!is_int($sortOrder) && !(is_string($sortOrder) && ctype_digit($sortOrder))) {
                throw new LocalizedException(__('The "sort_order" argument must be a whole number.'));
            }
            $rule->setSortOrder((int) $sortOrder);
            $changed[] = 'sort_order';
        }

        return $changed;
    }
}
