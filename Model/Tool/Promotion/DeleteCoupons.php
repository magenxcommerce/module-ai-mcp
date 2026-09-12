<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;

/**
 * Delete coupon codes.
 *
 * Magento's mass delete is all-or-nothing when told not to ignore invalid
 * coupons, which is the behaviour worth having — but it then reports the
 * problem as "Some coupons are invalid", naming none of them. So the codes are
 * looked up first and a missing one is refused by name, leaving the delete
 * itself to run only on codes that exist.
 */
class DeleteCoupons extends AbstractTool
{
    /** One call should not be able to wipe a whole campaign by accident. */
    private const MAX_CODES = 200;

    /**
     * @param CouponManagementInterface $couponManagement
     * @param CouponRepositoryInterface $couponRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly CouponManagementInterface $couponManagement,
        private readonly CouponRepositoryInterface $couponRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_coupons';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete coupon codes, named by code. This cannot be undone, and a code a customer '
            . 'already holds stops working immediately. Orders already placed with a deleted code '
            . 'are unaffected. Either every code given is deleted or none is. To stop a whole '
            . 'campaign instead, deactivate its rule with update_cart_price_rule, which is '
            . 'reversible.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'codes' => [
                    'type' => 'array',
                    'description' => 'Coupon codes to delete, at most ' . self::MAX_CODES . ' per call.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['codes'],
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
        $codes = $this->codes($arguments);
        $ruleIds = $this->assertAllExist($codes);

        // False means all-or-nothing: nothing is deleted unless every code
        // resolves. The check above is what makes that refusal legible.
        $result = $this->couponManagement->deleteByCodes($codes, false);

        $failed = array_values($result->getFailedItems());
        if ($failed !== []) {
            throw new LocalizedException(__(
                'Magento could not delete %1 of the %2 codes given: %3.',
                count($failed),
                count($codes),
                implode(', ', array_map(static fn ($code): string => (string) $code, $failed))
            ));
        }

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'deleted_count' => count($codes),
            'codes' => $codes,
            'rule_ids' => $ruleIds,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function codes(array $arguments): array
    {
        $codes = [];
        foreach ($this->optionalArray($arguments, 'codes') as $code) {
            if (!is_string($code) || trim($code) === '') {
                throw new LocalizedException(__('Every entry in "codes" must be a coupon code.'));
            }
            $codes[] = trim($code);
        }
        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            throw new LocalizedException(__('Pass at least one coupon code in "codes".'));
        }
        if (count($codes) > self::MAX_CODES) {
            throw new LocalizedException(
                __('Too many codes: %1 given, %2 at most per call.', count($codes), self::MAX_CODES)
            );
        }

        return $codes;
    }

    /**
     * Refuse the call unless every code exists, naming the ones that do not.
     *
     * @param string[] $codes
     * @return int[] The rules the codes belong to.
     * @throws LocalizedException
     */
    private function assertAllExist(array $codes): array
    {
        $this->searchCriteriaBuilder->addFilter('code', $codes, 'in');
        $this->searchCriteriaBuilder->setPageSize(count($codes));
        $found = $this->couponRepository->getList($this->searchCriteriaBuilder->create())->getItems();

        $foundCodes = [];
        $ruleIds = [];
        foreach ($found as $coupon) {
            /** @var CouponInterface $coupon */
            $foundCodes[] = (string) $coupon->getCode();
            $ruleIds[(int) $coupon->getRuleId()] = true;
        }

        $missing = array_values(array_diff($codes, $foundCodes));
        if ($missing !== []) {
            throw new LocalizedException(__(
                'No coupon exists with these codes: %1. Nothing was deleted. Check them with '
                . 'search_coupons.',
                implode(', ', $missing)
            ));
        }

        return array_keys($ruleIds);
    }
}
