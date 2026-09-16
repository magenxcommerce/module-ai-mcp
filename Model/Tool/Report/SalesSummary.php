<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * "How did last month go", in one call.
 */
class SalesSummary extends AbstractTool
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
        return 'sales_summary';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sales totals for a period: order count, what was ordered, what was actually '
            . 'invoiced, what was refunded, net, tax, shipping, discount and average order value. '
            . 'Canceled orders are excluded from the totals and reported separately, so nothing is '
            . 'silently counted or silently dropped. Figures are in the store\'s base currency and '
            . 'one block is returned per base currency — a multi-currency store gets several, '
            . 'because adding them together would be meaningless. Periods are read in the store\'s '
            . 'own timezone, unlike the date arguments on search_orders; the result reports both '
            . 'that range and the UTC one it queried.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties(),
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

        return [
            'period' => $period->toArray(),
            'totals' => $this->projector->toCurrencyTotals($this->aggregator->totals($period, $storeId)),
        ];
    }
}
