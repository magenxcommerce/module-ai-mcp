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
        return 'Sales totals for a period: order count, ordered, invoiced, refunded, net, tax, '
            . 'shipping, discount and average order value. Canceled orders are excluded from the '
            . 'totals and reported separately. Figures are in base currency, one block per base '
            . 'currency. Periods are read in the store\'s timezone, unlike the dates on '
            . 'search_orders. compare_to adds the previous period or year with the change; '
            . 'refund_basis dates refunds by order or by credit memo.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties()
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
        $period = $this->arguments->period($arguments, $storeId);
        $refundBasis = $this->arguments->refundBasis($arguments);
        $compareTo = $this->arguments->compareTo($arguments);

        $totals = $this->totals($period, $storeId, $refundBasis);
        $result = [
            'period' => $period->toArray(),
            'refund_basis' => $refundBasis,
            'totals' => $totals,
        ];

        if ($compareTo !== null) {
            $previous = $this->arguments->comparison($period, $compareTo);
            $previousTotals = $this->totals($previous, $storeId, $refundBasis);
            $result['comparison'] = [
                'compare_to' => $compareTo,
                'period' => $previous->toArray(),
                'totals' => $previousTotals,
                'change' => $this->projector->change($totals, $previousTotals),
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
    private function totals(Period $period, ?int $storeId, string $refundBasis): array
    {
        $rows = $this->aggregator->totals($period, $storeId);
        if ($refundBasis === ReportArguments::REFUND_BASIS_REFUND_DATE) {
            $rows = $this->projector->withRefundsByRefundDate(
                $rows,
                $this->aggregator->refundsByRefundDate($period, $storeId)
            );
        }

        return $this->projector->toCurrencyTotals($rows);
    }
}
