<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use Magento\SalesRule\Api\RuleRepositoryInterface;

/**
 * Generate coupon codes for a cart price rule.
 */
class GenerateCoupons extends AbstractTool
{
    /** Enough for a campaign; beyond this the codes stop fitting in one answer. */
    private const MAX_QUANTITY = 500;

    /**
     * @param CouponManagementInterface $couponManagement
     * @param CouponGenerationSpecInterfaceFactory $specFactory
     * @param RuleRepositoryInterface $ruleRepository
     */
    public function __construct(
        private readonly CouponManagementInterface $couponManagement,
        private readonly CouponGenerationSpecInterfaceFactory $specFactory,
        private readonly RuleRepositoryInterface $ruleRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'generate_coupons';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Generate coupon codes for a cart price rule and return them. The rule must be set '
            . 'to auto-generated coupons, which get_cart_price_rule reports as coupon_type 3 with '
            . 'use_auto_generation true. Every code generated is immediately usable if the rule is '
            . 'active, and codes cannot be un-generated — only deleted with delete_coupons.';
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
                    'description' => 'The cart price rule the coupons belong to.',
                ],
                'quantity' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_QUANTITY,
                    'description' => 'How many codes to generate, at most ' . self::MAX_QUANTITY . '.',
                ],
                'length' => [
                    'type' => 'integer',
                    'description' => 'Length of the random part of each code. Defaults to 12.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['alphanum', 'alpha', 'num'],
                    'description' => 'Character set for the random part. Defaults to "alphanum".',
                ],
                'prefix' => ['type' => 'string', 'description' => 'Text before the random part, e.g. "SUMMER-".'],
                'suffix' => ['type' => 'string', 'description' => 'Text after the random part.'],
                'delimiter' => [
                    'type' => 'string',
                    'description' => 'Separator inserted every delimiter_at_every characters, e.g. "-".',
                ],
                'delimiter_at_every' => [
                    'type' => 'integer',
                    'description' => 'How many characters between delimiters.',
                ],
            ],
            'required' => ['rule_id', 'quantity'],
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
        $quantity = $this->requireInt($arguments, 'quantity');

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new LocalizedException(
                __('The "quantity" argument must be between 1 and %1.', self::MAX_QUANTITY)
            );
        }

        // Checked here so that a wrong rule id is a clear error rather than
        // Magento's generic generation failure.
        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No cart price rule exists with rule_id %1.', $ruleId));
        }

        if (!$rule->getUseAutoGeneration()) {
            throw new LocalizedException(__(
                'Rule %1 ("%2") is not set to use auto-generated coupons, so codes cannot be '
                . 'generated for it. Its coupon type has to be changed in the admin first.',
                $ruleId,
                (string) $rule->getName()
            ));
        }

        $spec = $this->specFactory->create();
        $spec->setRuleId($ruleId);
        $spec->setQuantity($quantity);

        $length = $this->optionalInt($arguments, 'length');
        $spec->setLength($length ?? 12);

        $format = $this->optionalString($arguments, 'format');
        if ($format !== null) {
            if (!in_array($format, ['alphanum', 'alpha', 'num'], true)) {
                throw new LocalizedException(
                    __('The "format" argument must be "alphanum", "alpha" or "num".')
                );
            }
            $spec->setFormat($format);
        }

        foreach (['prefix' => 'setPrefix', 'suffix' => 'setSuffix', 'delimiter' => 'setDelimiter'] as $key => $setter) {
            $value = $this->optionalString($arguments, $key);
            if ($value !== null) {
                $spec->{$setter}($value);
            }
        }

        $delimiterAtEvery = $this->optionalInt($arguments, 'delimiter_at_every');
        if ($delimiterAtEvery !== null) {
            $spec->setDelimiterAtEvery($delimiterAtEvery);
        }

        $codes = $this->couponManagement->generate($spec);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'rule_id' => $ruleId,
            'rule_name' => $rule->getName(),
            'rule_is_active' => (bool) $rule->getIsActive(),
            'generated_count' => count($codes),
            'codes' => array_values($codes),
        ];
    }
}
