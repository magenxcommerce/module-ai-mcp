<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Change the settings of a cart price rule, short of its conditions.
 *
 * The rule is loaded and mutated rather than rebuilt. That is what keeps the
 * condition trees intact: Magento's repository converts the whole data object
 * back into the rule model on save, so a rule rebuilt from only the fields
 * passed here would be saved with no conditions at all — a discount that
 * suddenly applies to every cart.
 */
class UpdateCartPriceRule extends AbstractTool
{
    /**
     * @param RuleRepositoryInterface $ruleRepository
     * @param RuleConditions $conditions
     * @param CartPriceRuleProjector $projector
     */
    public function __construct(
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly RuleConditions $conditions,
        private readonly CartPriceRuleProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_cart_price_rule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a cart price rule: activate or deactivate it, move its date window, adjust '
            . 'the discount, or change which websites and customer groups it covers. Only the '
            . 'fields you pass are changed. It cannot change the rule\'s conditions or its coupon '
            . 'type — those stay as they are, and need the admin. Activating a rule makes it '
            . 'discount live carts immediately.';
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
                'is_active' => ['type' => 'boolean', 'description' => 'Whether the rule applies.'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'from_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'First day the rule applies, as "YYYY-MM-DD". Null clears it, '
                        . 'meaning no start limit.',
                ],
                'to_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'Last day the rule applies, as "YYYY-MM-DD". Null clears it, '
                        . 'meaning the rule does not expire.',
                ],
                'discount_amount' => [
                    'type' => 'number',
                    'description' => 'The discount, read according to the rule\'s existing '
                        . 'simple_action: a percentage, a fixed amount off the cart, or a fixed '
                        . 'amount off each item. get_cart_price_rule reports which.',
                ],
                'discount_qty' => [
                    'type' => 'number',
                    'description' => 'Largest quantity the discount applies to. 0 means no limit.',
                ],
                'discount_step' => [
                    'type' => 'integer',
                    'description' => 'Buy-X-get-Y step, where the action uses one.',
                ],
                'uses_per_customer' => [
                    'type' => 'integer',
                    'description' => 'How many times one customer may use the rule. 0 means no limit.',
                ],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Order the rule is applied in, lower first.',
                ],
                'apply_to_shipping' => ['type' => 'boolean', 'description' => 'Apply the discount to shipping too.'],
                'stop_rules_processing' => [
                    'type' => 'boolean',
                    'description' => 'Stop applying any further rule once this one matches.',
                ],
                'website_ids' => [
                    'type' => 'array',
                    'description' => 'Websites the rule covers, replacing the current list. '
                        . 'list_stores reports the ids.',
                    'items' => ['type' => 'integer'],
                ],
                'customer_group_ids' => [
                    'type' => 'array',
                    'description' => 'Customer groups the rule covers, replacing the current list. '
                        . 'list_customer_groups reports the ids.',
                    'items' => ['type' => 'integer'],
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
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No cart price rule exists with rule_id %1.', $ruleId));
        }

        $changed = array_merge(
            $this->applyBooleans($rule, $arguments),
            $this->applyStrings($rule, $arguments),
            $this->applyNumbers($rule, $arguments),
            $this->applyIdLists($rule, $arguments)
        );

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides rule_id.'));
        }

        $this->assertActivationIsSafe($rule, $arguments);

        $saved = $this->ruleRepository->save($rule);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toSummary($saved);
    }

    /**
     * Refuse to switch on a rule that restricts nothing.
     *
     * This tool cannot write conditions — see the class docblock — so a rule
     * that reached the database without them cannot be given any here, and
     * activating it makes it discount every cart in the store immediately.
     * Nothing about the request looks wrong when that happens: the field being
     * set is a boolean, the rule saves, and the first sign of trouble is the
     * revenue.
     *
     * Only activation is blocked. Editing the name or the dates of an
     * already-live unconditioned rule is left alone — that rule is somebody's
     * deliberate store-wide promotion, and refusing to touch it would be this
     * tool inventing policy rather than preventing an accident.
     *
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    private function assertActivationIsSafe(RuleInterface $rule, array $arguments): void
    {
        if (($arguments['is_active'] ?? false) !== true) {
            return;
        }

        if ($this->conditions->cartRuleIsRestricted($rule)) {
            return;
        }

        throw new LocalizedException(__(
            'Rule %1 has no conditions, so activating it would discount every cart in the store. '
            . 'Conditions cannot be set through this server — add them in the admin and enable it '
            . 'there. Everything else about the rule can still be changed here.',
            (int) $rule->getRuleId()
        ));
    }

    /**
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyBooleans(RuleInterface $rule, array $arguments): array
    {
        $setters = [
            'is_active' => 'setIsActive',
            'apply_to_shipping' => 'setApplyToShipping',
            'stop_rules_processing' => 'setStopRulesProcessing',
        ];

        $changed = [];
        foreach ($setters as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$key])) {
                throw new LocalizedException(
                    __('The "%1" argument must be true or false, not a string or a number.', $key)
                );
            }
            $rule->{$setter}($arguments[$key]);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyStrings(RuleInterface $rule, array $arguments): array
    {
        $changed = [];

        foreach (['name' => 'setName', 'description' => 'setDescription'] as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if (!is_string($arguments[$key])) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $rule->{$setter}($arguments[$key]);
            $changed[] = $key;
        }

        // The dates are nullable on purpose: an open-ended rule is expressed by
        // clearing them, which a plain string argument could not do.
        foreach (['from_date' => 'setFromDate', 'to_date' => 'setToDate'] as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(
                    __('The "%1" argument must be a date as "YYYY-MM-DD", or null to clear it.', $key)
                );
            }
            $rule->{$setter}($value);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyNumbers(RuleInterface $rule, array $arguments): array
    {
        $floats = ['discount_amount' => 'setDiscountAmount', 'discount_qty' => 'setDiscountQty'];
        $integers = [
            'discount_step' => 'setDiscountStep',
            'uses_per_customer' => 'setUsesPerCustomer',
            'sort_order' => 'setSortOrder',
        ];

        $changed = [];

        foreach ($floats as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                throw new LocalizedException(__('The "%1" argument must be a number.', $key));
            }
            if ((float) $value < 0) {
                throw new LocalizedException(__('The "%1" argument cannot be negative.', $key));
            }
            $rule->{$setter}((float) $value);
            $changed[] = $key;
        }

        foreach ($integers as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new LocalizedException(__('The "%1" argument must be a whole number.', $key));
            }
            $rule->{$setter}((int) $value);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * @param RuleInterface $rule
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyIdLists(RuleInterface $rule, array $arguments): array
    {
        $setters = [
            'website_ids' => 'setWebsiteIds',
            'customer_group_ids' => 'setCustomerGroupIds',
        ];

        $changed = [];
        foreach ($setters as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }

            $value = $arguments[$key];
            if (!is_array($value) || $value === []) {
                // An empty list is a rule that applies nowhere, which is almost
                // certainly not what was meant; deactivating it says so clearly.
                throw new LocalizedException(__(
                    'The "%1" argument must be a non-empty list of ids. To stop the rule applying, '
                    . 'set is_active to false instead.',
                    $key
                ));
            }

            $ids = [];
            foreach ($value as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    throw new LocalizedException(__('Every entry in "%1" must be a whole number.', $key));
                }
                $ids[] = (int) $id;
            }

            $rule->{$setter}(array_values(array_unique($ids)));
            $changed[] = $key;
        }

        return $changed;
    }
}
