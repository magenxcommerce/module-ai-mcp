<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * What will run out, and when, at the rate it is selling.
 */
class InventoryRisk extends AbstractTool
{
    private const DEFAULT_VELOCITY_DAYS = 30;
    private const MAX_VELOCITY_DAYS = 365;
    private const DEFAULT_THRESHOLD_DAYS = 14;
    private const MAX_THRESHOLD_DAYS = 365;
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    /**
     * @param ReportArguments $arguments
     * @param StockAggregator $aggregator
     * @param CoreDate $date
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly StockAggregator $aggregator,
        private readonly CoreDate $date
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'inventory_risk';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Products that will run out within threshold_days at their recent sales rate, soonest '
            . 'first: quantity on hand, units sold in the last velocity_days, daily rate and days of '
            . 'stock left (0 when already out). Only products that sold and whose stock is managed. '
            . 'Quantity is the legacy single stock that get_stock reads — the default source under '
            . 'MSI.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'velocity_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_VELOCITY_DAYS,
                    'description' => 'Days of sales the rate is measured over, ending now. Defaults to '
                        . self::DEFAULT_VELOCITY_DAYS . '.',
                ],
                'threshold_days' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_THRESHOLD_DAYS,
                    'description' => 'Report products with at most this many days of stock left. '
                        . 'Defaults to ' . self::DEFAULT_THRESHOLD_DAYS . '.',
                ],
                'store_code' => [
                    'type' => 'string',
                    'description' => 'Measure the sales rate on one store view only. Stock itself is '
                        . 'shared by every store.',
                ],
            ] + $this->arguments->limitSchema('products', self::DEFAULT_LIMIT, self::MAX_LIMIT),
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
        $velocityDays = $this->arguments->boundedInt(
            $arguments,
            'velocity_days',
            self::DEFAULT_VELOCITY_DAYS,
            1,
            self::MAX_VELOCITY_DAYS
        );
        $threshold = $this->arguments->boundedInt(
            $arguments,
            'threshold_days',
            self::DEFAULT_THRESHOLD_DAYS,
            0,
            self::MAX_THRESHOLD_DAYS
        );
        $limit = $this->arguments->boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $soldSince = gmdate('Y-m-d H:i:s', (int) $this->date->gmtTimestamp() - $velocityDays * 86400);

        return [
            'sold_since_utc' => $soldSince,
            'velocity_days' => $velocityDays,
            'threshold_days' => $threshold,
            'limit' => $limit,
            'items' => array_map(
                static function (array $row) use ($velocityDays): array {
                    $units = (float) ($row['units_sold'] ?? 0);

                    return [
                        'product_id' => (int) ($row['product_id'] ?? 0),
                        'sku' => $row['sku'] ?? null,
                        'name' => $row['name'] ?? null,
                        'qty' => (float) ($row['qty'] ?? 0),
                        'is_in_stock' => (bool) ($row['is_in_stock'] ?? false),
                        'units_sold' => $units,
                        'units_per_day' => round($units / $velocityDays, 4),
                        'days_left' => round((float) ($row['days_left'] ?? 0), 1),
                    ];
                },
                $this->aggregator->atRisk($soldSince, $velocityDays, (float) $threshold, $storeId, $limit)
            ),
        ];
    }
}
