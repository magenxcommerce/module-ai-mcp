<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Start a catalog price rule, inactive.
 *
 * Same shape and same reason as {@see CreateCartPriceRule}, with a larger blast
 * radius: a catalog rule with no condition matches every product in the
 * catalogue, and conditions cannot be written through this server. So the rule
 * is created switched off, `is_active` is not an argument, and a person adds the
 * condition and enables it in the admin.
 *
 * The other half of the guard is on the update side — {@see
 * UpdateCatalogPriceRule} refuses to activate a rule with no condition — so the
 * shell this creates cannot be switched on here either.
 *
 * Note the date fields. Magento's catalog rule contract calls them `start_date`
 * and `end_date`, not the `from_date`/`to_date` a cart rule uses; the arguments
 * are named after the contract rather than made to match the sibling tool.
 */
class CreateCatalogPriceRule extends AbstractTool
{
    /**
     * @param RuleInterfaceFactory $ruleFactory
     * @param CatalogRuleRepositoryInterface $catalogRuleRepository
     * @param CatalogPriceRuleProjector $projector
     */
    public function __construct(
        private readonly RuleInterfaceFactory $ruleFactory,
        private readonly CatalogRuleRepositoryInterface $catalogRuleRepository,
        private readonly CatalogPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_catalog_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a catalog price rule. It is ALWAYS created inactive and cannot be activated '
            . 'through this server: conditions cannot be written here, and a catalog rule with no '
            . 'condition re-prices every product in the catalogue. Set this up, then add the '
            . 'condition and switch it on in the admin. Everything else — name, dates, discount, '
            . 'websites, customer groups — can be set here and changed later with '
            . 'update_catalog_price_rule.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'What the rule is called in the admin.'],
                'description' => ['type' => 'string', 'description' => 'Internal note about the rule.'],
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
                'website_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Websites the rule applies to; list_stores reports the ids.',
                ],
                'customer_group_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Customer groups the rule applies to; list_customer_groups '
                        . 'reports the ids.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'First day the rule may apply, "YYYY-MM-DD". Omit for no start.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Last day the rule may apply, "YYYY-MM-DD". Omit for no end.',
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
            'required' => ['name', 'simple_action', 'discount_amount', 'website_ids', 'customer_group_ids'],
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
        // Adds an inactive rule; touches nothing that already exists.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rule = $this->ruleFactory->create();

        $rule->setName($this->requireString($arguments, 'name'));
        $rule->setSimpleAction($this->simpleAction($arguments));
        $rule->setDiscountAmount($this->discountAmount($arguments));
        $rule->setWebsiteIds($this->idList($arguments, 'website_ids'));
        $rule->setCustomerGroupIds($this->idList($arguments, 'customer_group_ids'));

        // Not negotiable and not an argument: see the class docblock.
        $rule->setIsActive(false);

        $description = $this->optionalString($arguments, 'description');
        if ($description !== null) {
            $rule->setDescription($description);
        }
        foreach (['start_date' => 'setStartDate', 'end_date' => 'setEndDate'] as $key => $setter) {
            $value = $this->optionalString($arguments, $key);
            if ($value !== null) {
                $rule->{$setter}($value);
            }
        }
        $sortOrder = $this->optionalInt($arguments, 'sort_order');
        if ($sortOrder !== null) {
            $rule->setSortOrder($sortOrder);
        }
        $stop = $this->optionalBool($arguments, 'stop_rules_processing');
        if ($stop !== null) {
            $rule->setStopRulesProcessing($stop);
        }

        $saved = $this->catalogRuleRepository->save($rule);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'is_active' => false,
            'next_step' => 'The rule is inactive and has no condition, so it currently matches '
                . 'nothing. Add its condition in the admin and enable it there.',
        ] + $this->projector->toArray($saved);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function simpleAction(array $arguments): string
    {
        $action = $this->requireString($arguments, 'simple_action');
        $allowed = ['by_percent', 'by_fixed', 'to_percent', 'to_fixed'];

        if (!in_array($action, $allowed, true)) {
            throw new LocalizedException(__(
                'The "simple_action" argument must be one of: %1.',
                implode(', ', $allowed)
            ));
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return float
     * @throws LocalizedException
     */
    private function discountAmount(array $arguments): float
    {
        $value = $arguments['discount_amount'] ?? null;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('The "discount_amount" argument must be a number.'));
        }

        $amount = (float) $value;
        if ($amount < 0) {
            throw new LocalizedException(__('The "discount_amount" argument cannot be negative.'));
        }

        return $amount;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return int[]
     * @throws LocalizedException
     */
    private function idList(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;
        if (!is_array($value) || $value === []) {
            // An empty list is not a rule that applies to everything — it is a
            // rule that applies to nothing, saved without complaint.
            throw new LocalizedException(
                __('The "%1" argument must be a non-empty array of ids.', $key)
            );
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new LocalizedException(__('Every id in "%1" must be a whole number.', $key));
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
