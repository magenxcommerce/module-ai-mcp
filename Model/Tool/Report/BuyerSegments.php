<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * New, returning and inactive buyers — counts, never names.
 */
class BuyerSegments extends AbstractTool
{
    private const DEFAULT_INACTIVE_DAYS = 180;
    private const MAX_INACTIVE_DAYS = 3660;

    /**
     * @param ReportArguments $arguments
     * @param BuyerAggregator $aggregator
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly BuyerAggregator $aggregator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'buyer_segments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Buyers in a period split into new (first order ever falls in the period) and '
            . 'returning (ordered before too), with their orders and revenue, plus how many known '
            . 'buyers are inactive (no order in inactive_days before the period end). A buyer is an '
            . 'e-mail address, so guest and account orders of one person count once. Reads each '
            . 'buyer\'s full history up to the period end; canceled orders count for nothing.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties() + [
                'inactive_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_INACTIVE_DAYS,
                    'description' => 'A buyer whose last order is more than this many days before the '
                        . 'end of the period counts as inactive. Defaults to '
                        . self::DEFAULT_INACTIVE_DAYS . '.',
                ],
            ],
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
        $inactiveDays = $this->arguments->boundedInt(
            $arguments,
            'inactive_days',
            self::DEFAULT_INACTIVE_DAYS,
            1,
            self::MAX_INACTIVE_DAYS
        );
        $inactiveBefore = (new \DateTimeImmutable($period->getUtcTo(), new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $inactiveDays))
            ->format('Y-m-d H:i:s');

        return [
            'period' => $period->toArray(),
            'inactive_days' => $inactiveDays,
            'inactive_before_utc' => $inactiveBefore,
            'segments' => array_map(
                static function (array $row): array {
                    $newBuyers = (int) ($row['new_buyers'] ?? 0);
                    $returning = (int) ($row['returning_buyers'] ?? 0);
                    $active = $newBuyers + $returning;

                    return [
                        'currency' => $row['currency'] ?? null,
                        'buyers_in_period' => $active,
                        'new_buyers' => $newBuyers,
                        'new_buyer_orders' => (int) ($row['new_orders'] ?? 0),
                        'new_buyer_revenue' => (float) ($row['new_revenue'] ?? 0),
                        'returning_buyers' => $returning,
                        'returning_buyer_orders' => (int) ($row['returning_orders'] ?? 0),
                        'returning_buyer_revenue' => (float) ($row['returning_revenue'] ?? 0),
                        'returning_share_percent' => $active > 0 ? round($returning / $active * 100, 2) : null,
                        'known_buyers' => (int) ($row['known_buyers'] ?? 0),
                        'inactive_buyers' => (int) ($row['inactive_buyers'] ?? 0),
                    ];
                },
                $this->aggregator->segments($period, $storeId, $inactiveBefore)
            ),
        ];
    }
}
