<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Promotion;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;

/**
 * Find coupon codes.
 */
class SearchCoupons extends AbstractTool
{
    /**
     * @param CouponRepositoryInterface $couponRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly CouponRepositoryInterface $couponRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_coupons';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List coupon codes, optionally narrowed to one cart price rule or one code. Each '
            . 'result reports how many times it has been used and its usage limits.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'rule_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to coupons of one cart price rule.',
                    ],
                    'code' => ['type' => 'string', 'description' => 'Exact coupon code.'],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $ruleId = $this->optionalInt($arguments, 'rule_id');
        if ($ruleId !== null) {
            $this->searchCriteriaBuilder->addFilter('rule_id', $ruleId);
        }
        $code = $this->optionalString($arguments, 'code');
        if ($code !== null) {
            $this->searchCriteriaBuilder->addFilter('code', $code);
        }

        $result = $this->couponRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (CouponInterface $coupon): array => [
                    'coupon_id' => (int) $coupon->getCouponId(),
                    'code' => $coupon->getCode(),
                    'rule_id' => (int) $coupon->getRuleId(),
                    'times_used' => $coupon->getTimesUsed() === null ? null : (int) $coupon->getTimesUsed(),
                    'usage_limit' => $coupon->getUsageLimit() === null ? null : (int) $coupon->getUsageLimit(),
                    'usage_per_customer' => $coupon->getUsagePerCustomer() === null
                        ? null
                        : (int) $coupon->getUsagePerCustomer(),
                    'expiration_date' => $coupon->getExpirationDate(),
                    'is_primary' => (bool) $coupon->getIsPrimary(),
                    'created_at' => $coupon->getCreatedAt(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
