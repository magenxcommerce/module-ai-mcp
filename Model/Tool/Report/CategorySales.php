<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Revenue, units, orders and average order value per category.
 */
class CategorySales extends AbstractTool
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 200;

    /**
     * @param ReportArguments $arguments
     * @param CategoryAggregator $aggregator
     * @param CategoryNames $names
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly CategoryAggregator $aggregator,
        private readonly CategoryNames $names
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'category_sales';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sales per category over a period, highest revenue first: orders, units, revenue (net '
            . 'of tax), discount and average order value. A product in several categories is either '
            . 'split between them ("split", the default — categories add up to what was sold) or '
            . 'counted in full in each ("full" — they add up to more). Uses today\'s category '
            . 'assignments; canceled orders are left out.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties() + [
                'allocation' => [
                    'type' => 'string',
                    'enum' => [CategoryAggregator::ALLOCATION_SPLIT, CategoryAggregator::ALLOCATION_FULL],
                    'description' => 'How a sale of a product in several categories is counted. '
                        . 'Defaults to "split".',
                ],
                'parent_category_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Report only the categories below this one. The split is then '
                        . 'between those categories alone.',
                ],
            ] + $this->arguments->limitSchema('categories', self::DEFAULT_LIMIT, self::MAX_LIMIT),
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
        $allocation = $this->optionalString($arguments, 'allocation', CategoryAggregator::ALLOCATION_SPLIT);
        if (!in_array($allocation, [CategoryAggregator::ALLOCATION_SPLIT, CategoryAggregator::ALLOCATION_FULL], true)) {
            throw new LocalizedException(__('The "allocation" argument must be "split" or "full".'));
        }
        $parent = $this->optionalInt($arguments, 'parent_category_id');
        $limit = $this->arguments->boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $rows = $this->aggregator->categorySales($period, $storeId, $allocation, $parent, $limit);
        $names = $this->names->forIds(array_map(static fn (array $row): int => (int) $row['category_id'], $rows));

        return [
            'period' => $period->toArray(),
            'allocation' => $allocation,
            // Said in the result as well as the description, because this is
            // the figure somebody adds up.
            'sums_exceed_total' => $allocation === CategoryAggregator::ALLOCATION_FULL,
            'parent_category_id' => $parent,
            'limit' => $limit,
            'line_totals' => array_map(
                static fn (array $row): array => [
                    'currency' => $row['currency'] ?? null,
                    'revenue' => (float) ($row['revenue'] ?? 0),
                ],
                $this->aggregator->lineTotals($period, $storeId)
            ),
            'items' => array_map(
                static function (array $row) use ($names): array {
                    $id = (int) $row['category_id'];
                    $orders = (int) ($row['orders'] ?? 0);
                    $revenue = round((float) ($row['revenue'] ?? 0), 4);

                    return [
                        'category_id' => $id,
                        'name' => $names[$id] ?? null,
                        'path' => $row['path'] ?? null,
                        'currency' => $row['currency'] ?? null,
                        'orders' => $orders,
                        'units' => round((float) ($row['units'] ?? 0), 4),
                        'revenue' => $revenue,
                        'discount' => round((float) ($row['discount'] ?? 0), 4),
                        'average_order_value' => $orders > 0 ? round($revenue / $orders, 4) : null,
                    ];
                },
                $rows
            ),
        ];
    }
}
