<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Who bought over a period, and who spent the most.
 */
class CustomerSummary extends AbstractTool
{
    private const DEFAULT_LIMIT = 20;
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
        return 'customer_summary';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Who bought over a period: how many distinct customers, how the orders split between '
            . 'guest checkout and account holders, and the biggest spenders. Customers are counted '
            . 'by e-mail address so a guest checkout counts as the person it was rather than as a '
            . 'blank. New versus returning is deliberately NOT reported: answering it correctly '
            . 'means reading every customer\'s whole order history, and a cheap approximation of it '
            . 'is the kind of number somebody would quote. Use search_orders filtered by '
            . 'customer_email to settle it for one customer.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties() + [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'How many top customers to return (default ' . self::DEFAULT_LIMIT
                        . ', maximum ' . self::MAX_LIMIT . ').',
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
        $limit = max(1, min($this->optionalInt($arguments, 'limit') ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        return [
            'period' => $period->toArray(),
            'totals' => array_map(
                static fn (array $row): array => [
                    'currency' => $row['currency'] ?? null,
                    'customers' => (int) ($row['customers'] ?? 0),
                    'guest_orders' => (int) ($row['guest_orders'] ?? 0),
                    'account_orders' => (int) ($row['account_orders'] ?? 0),
                ],
                $this->aggregator->customerTotals($period, $storeId)
            ),
            'top_customers' => array_map(
                static fn (array $row): array => [
                    'customer_email' => $row['customer_email'] ?? null,
                    'customer_id' => isset($row['customer_id']) ? (int) $row['customer_id'] : null,
                    'currency' => $row['currency'] ?? null,
                    'orders' => (int) ($row['orders'] ?? 0),
                    'ordered_total' => (float) ($row['ordered_total'] ?? 0),
                ],
                $this->aggregator->topCustomers($period, $storeId, $limit)
            ),
        ];
    }
}
