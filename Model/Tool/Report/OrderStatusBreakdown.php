<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Where the period's orders actually are.
 */
class OrderStatusBreakdown extends AbstractTool
{
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
        return 'order_status_breakdown';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Order counts and value for a period grouped by status, busiest first, with the '
            . 'underlying state beside each. This is the triage view: it shows work piled up in '
            . 'pending or payment review, and how much money is sitting in it. Unlike '
            . 'sales_summary, canceled orders appear here as their own rows rather than being '
            . 'separated out — seeing them is the point.';
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
            'items' => array_map(
                static fn (array $row): array => [
                    'status' => $row['status'] ?? null,
                    'state' => $row['state'] ?? null,
                    'currency' => $row['currency'] ?? null,
                    'orders' => (int) ($row['orders'] ?? 0),
                    'ordered_total' => (float) ($row['ordered_total'] ?? 0),
                ],
                $this->aggregator->statusBreakdown($period, $storeId)
            ),
        ];
    }
}
