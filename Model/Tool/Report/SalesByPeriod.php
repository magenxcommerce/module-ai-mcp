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
            . '200 buckets are returned — a longer range needs a coarser granularity.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties(true),
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

        return [
            'period' => $period->toArray(),
            'buckets' => $this->projector->toBuckets($this->aggregator->totalsByBucket($period, $storeId)),
        ];
    }
}
