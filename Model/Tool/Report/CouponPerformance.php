<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Which coupons are used, what they cost, and how coupon orders compare.
 */
class CouponPerformance extends AbstractTool
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    /**
     * @param ReportArguments $arguments
     * @param SalesAggregator $aggregator
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly SalesAggregator $aggregator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'coupon_performance';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Coupon codes used over a period, most used first: orders, revenue, discount given, '
            . 'average order value and the cart price rule each belongs to. Also reports the '
            . 'baseline — orders and average order value with and without a coupon — which is what '
            . 'a coupon\'s numbers mean anything against. Canceled orders are left out. Discounts '
            . 'are negative, as Magento stores them.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties()
                + $this->arguments->limitSchema('coupon codes', self::DEFAULT_LIMIT, self::MAX_LIMIT),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return ReportArguments::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $storeId = $this->arguments->storeId($arguments);
        $period = $this->arguments->period($arguments, $storeId);
        $limit = $this->arguments->boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        return [
            'period' => $period->toArray(),
            'limit' => $limit,
            'baseline' => array_map(
                fn (array $row): array => $this->baseline($row),
                $this->aggregator->couponBaseline($period, $storeId)
            ),
            'items' => array_map(
                fn (array $row): array => $this->coupon($row),
                $this->aggregator->couponPerformance($period, $storeId, $limit)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function coupon(array $row): array
    {
        $orders = (int) ($row['orders'] ?? 0);
        $ordered = (float) ($row['ordered_total'] ?? 0);

        return [
            'coupon_code' => $row['coupon_code'] ?? null,
            'rule_id' => isset($row['rule_id']) ? (int) $row['rule_id'] : null,
            'rule_name' => $row['rule_name'] ?? null,
            'currency' => $row['currency'] ?? null,
            'orders' => $orders,
            'ordered_total' => $ordered,
            'discount_total' => (float) ($row['discount_total'] ?? 0),
            'average_order_value' => $orders > 0 ? round($ordered / $orders, 4) : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function baseline(array $row): array
    {
        $with = (int) ($row['orders_with_coupon'] ?? 0);
        $without = (int) ($row['orders_without_coupon'] ?? 0);
        $withTotal = (float) ($row['ordered_with_coupon'] ?? 0);
        $withoutTotal = (float) ($row['ordered_without_coupon'] ?? 0);

        return [
            'currency' => $row['currency'] ?? null,
            'orders_with_coupon' => $with,
            'orders_without_coupon' => $without,
            'coupon_order_share_percent' => $with + $without > 0 ? round($with / ($with + $without) * 100, 2) : null,
            'average_order_value_with_coupon' => $with > 0 ? round($withTotal / $with, 4) : null,
            'average_order_value_without_coupon' => $without > 0 ? round($withoutTotal / $without, 4) : null,
        ];
    }
}
