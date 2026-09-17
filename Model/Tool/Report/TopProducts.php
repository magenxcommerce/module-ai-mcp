<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * What sold, over a period.
 */
class TopProducts extends AbstractTool
{
    /** Bestseller lists are read, not paged; a long tail is a different question. */
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
        return 'top_products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Bestselling products over a period, ranked by units sold or by revenue. Revenue is '
            . 'net of tax and in the store\'s base currency, and rows are grouped by currency. '
            . 'Only top-level order lines are counted: a configurable product records both itself '
            . 'and its simple variant, so counting every line would double every configurable sold '
            . '— the sku reported is therefore the one the customer chose, not the variant. '
            . 'Quantities are as ordered, so a later refund does not remove them; compare with '
            . 'sales_summary for what was actually kept.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties() + [
                'rank_by' => [
                    'type' => 'string',
                    'enum' => ['quantity', 'revenue'],
                    'description' => 'What to sort by. Defaults to "quantity". The two lists differ '
                        . 'sharply on a catalogue with a wide price range.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'How many products to return (default ' . self::DEFAULT_LIMIT
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

        $rankBy = $this->optionalString($arguments, 'rank_by', 'quantity');
        if (!in_array($rankBy, ['quantity', 'revenue'], true)) {
            throw new LocalizedException(__('The "rank_by" argument must be "quantity" or "revenue".'));
        }

        $limit = max(1, min($this->optionalInt($arguments, 'limit') ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $items = $this->aggregator->topProducts($period, $storeId, $rankBy === 'revenue', $limit);

        return [
            'period' => $period->toArray(),
            'rank_by' => $rankBy,
            'limit' => $limit,
            'items' => array_map(
                static fn (array $row): array => [
                    'sku' => $row['sku'] ?? null,
                    'name' => $row['name'] ?? null,
                    'currency' => $row['currency'] ?? null,
                    'qty_ordered' => (float) ($row['qty'] ?? 0),
                    'revenue' => (float) ($row['revenue'] ?? 0),
                    'orders' => (int) ($row['orders'] ?? 0),
                ],
                $items
            ),
        ];
    }
}
