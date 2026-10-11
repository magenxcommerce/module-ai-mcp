<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * The same totals as sales_summary, bucketed so a trend is visible.
 */
class SalesByPeriod extends AbstractTool
{
    /**
     * @param ReportArguments $arguments
     * @param SalesAggregator $aggregator
     * @param TotalsProjector $projector
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly SalesAggregator $aggregator,
        private readonly TotalsProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'sales_by_period';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'The sales_summary figures broken into day, week or month buckets, oldest first, so '
            . 'a trend or a bad week is visible. Weeks start on Monday. Buckets are cut in the '
            . 'store\'s own timezone; where a range crosses a daylight-saving change an individual '
            . 'bucket can be an hour out at its edge, though the period total is exact. At most '
            . '200 buckets are returned — a longer range needs a coarser granularity. compare_to '
            . 'adds the earlier period\'s buckets; refund_basis works as on sales_summary.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties(true)
                + $this->arguments->totalsOptionSchemaProperties(),
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
        $period = $this->arguments->period($arguments, $storeId, true);
        $refundBasis = $this->arguments->refundBasis($arguments);
        $compareTo = $this->arguments->compareTo($arguments);

        $result = [
            'period' => $period->toArray(),
            'refund_basis' => $refundBasis,
            'buckets' => $this->buckets($period, $storeId, $refundBasis),
        ];

        if ($compareTo !== null) {
            // The earlier period's own buckets, oldest first. Not paired with
            // this period's bucket by bucket: a month back from a 31-day month
            // has a different number of days, and a pairing would have to
            // invent which day matches which.
            $previous = $this->arguments->comparison($period, $compareTo);
            $result['comparison'] = [
                'compare_to' => $compareTo,
                'period' => $previous->toArray(),
                'buckets' => $this->buckets($previous, $storeId, $refundBasis),
            ];
        }

        return $result;
    }

    /**
     * @param Period $period
     * @param int|null $storeId
     * @param string $refundBasis
     * @return array<int, array<string, mixed>>
     */
    private function buckets(Period $period, ?int $storeId, string $refundBasis): array
    {
        $rows = $this->aggregator->totalsByBucket($period, $storeId);
        if ($refundBasis === ReportArguments::REFUND_BASIS_REFUND_DATE) {
            $rows = $this->projector->withRefundsByRefundDate(
                $rows,
                $this->aggregator->refundsByRefundDateBucket($period, $storeId)
            );
        }

        return $this->projector->toBuckets($rows);
    }
}
