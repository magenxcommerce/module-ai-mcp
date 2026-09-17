<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\Data\RuleInterfaceFactory;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Start a cart price rule, inactive.
 *
 * The rule is created switched off and `is_active` is not an argument at all.
 * That is the whole design rather than a precaution: no tool in this server
 * writes rule conditions — Magento's repository rebuilds the rule from the data
 * object on save, so anything assembled here would be stored with none — and a
 * cart rule with no conditions discounts every cart in the store.
 *
 * So this tool does the half it can do safely: the name, the dates, the
 * discount, the websites and customer groups it applies to. Somebody then opens
 * it in the admin, adds the conditions, and enables it there. The half that is
 * left is also the half that needs a person, which is why splitting it this way
 * costs less than it sounds.
 *
 * {@see UpdateCartPriceRule} refuses to activate a rule with no conditions, so
 * the shell this creates cannot be switched on through the server either.
 */
class CreateCartPriceRule extends AbstractTool
{
    /** Magento's own default when a rule does not say. */
    private const DEFAULT_COUPON_TYPE = 'NO_COUPON';

    /**
     * @param RuleInterfaceFactory $ruleFactory
     * @param RuleRepositoryInterface $ruleRepository
     * @param CartPriceRuleProjector $projector
     */
    public function __construct(
        private readonly RuleInterfaceFactory $ruleFactory,
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly CartPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_cart_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a cart price rule. It is ALWAYS created inactive and cannot be activated '
            . 'through this server: conditions cannot be written here, and a cart rule with no '
            . 'conditions discounts every cart in the store. Set this up, then add the conditions '
            . 'and switch it on in the admin. Everything else — name, dates, discount, websites, '
            . 'customer groups — can be set here and changed later with update_cart_price_rule.';
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
                    'enum' => ['by_percent', 'by_fixed', 'cart_fixed', 'buy_x_get_y'],
                    'description' => 'How the discount is calculated. "by_percent" takes a '
                        . 'percentage off each matching item; "cart_fixed" takes an amount off the '
                        . 'cart total.',
                ],
                'discount_amount' => [
                    'type' => 'number',
                    'description' => 'The percentage or amount, depending on simple_action.',
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
                'from_date' => [
                    'type' => 'string',
                    'description' => 'First day the rule may apply, "YYYY-MM-DD". Omit for no start.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Last day the rule may apply, "YYYY-MM-DD". Omit for no end.',
                ],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Order rules are evaluated in, lower first.',
                ],
                'uses_per_customer' => [
                    'type' => 'integer',
                    'description' => 'How many times one customer may use it. 0 means unlimited.',
                ],
                'stop_rules_processing' => [
                    'type' => 'boolean',
                    'description' => 'Stop evaluating further rules once this one applies.',
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
        $rule->setCouponType(self::DEFAULT_COUPON_TYPE);

        $description = $this->optionalString($arguments, 'description');
        if ($description !== null) {
            $rule->setDescription($description);
        }
        foreach (['from_date' => 'setFromDate', 'to_date' => 'setToDate'] as $key => $setter) {
            $value = $this->optionalString($arguments, $key);
            if ($value !== null) {
                $rule->{$setter}($value);
            }
        }
        foreach (['sort_order' => 'setSortOrder', 'uses_per_customer' => 'setUsesPerCustomer'] as $key => $setter) {
            $value = $this->optionalInt($arguments, $key);
            if ($value !== null) {
                $rule->{$setter}($value);
            }
        }
        $stop = $this->optionalBool($arguments, 'stop_rules_processing');
        if ($stop !== null) {
            $rule->setStopRulesProcessing($stop);
        }

        $saved = $this->ruleRepository->save($rule);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'is_active' => false,
            'next_step' => 'The rule is inactive and has no conditions, so it currently matches '
                . 'nothing. Add its conditions in the admin and enable it there.',
        ] + $this->projector->toSummary($saved);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function simpleAction(array $arguments): string
    {
        $action = $this->requireString($arguments, 'simple_action');
        $allowed = ['by_percent', 'by_fixed', 'cart_fixed', 'buy_x_get_y'];

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
