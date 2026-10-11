<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * "Where are we selling", in one call.
 */
class SalesByCountry extends AbstractTool
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 250;
    private const ADDRESS_TYPES = ['billing', 'shipping'];

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
        return 'sales_by_country';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'The sales_summary figures per country, by billing or shipping address, largest first. '
            . 'Orders without that address type — a virtual order has no shipping address — are '
            . 'reported under a null country_id, so the countries add up to sales_summary. One row '
            . 'per country and base currency.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties() + [
                'address_type' => [
                    'type' => 'string',
                    'enum' => self::ADDRESS_TYPES,
                    'description' => 'Which address decides the country. Defaults to "billing".',
                ],
            ] + $this->arguments->limitSchema('countries', self::DEFAULT_LIMIT, self::MAX_LIMIT),
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
        $addressType = $this->optionalString($arguments, 'address_type', 'billing');
        if (!in_array($addressType, self::ADDRESS_TYPES, true)) {
            throw new LocalizedException(__('The "address_type" argument must be "billing" or "shipping".'));
        }
        $limit = $this->arguments->boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $rows = $this->aggregator->totalsByCountry($period, $storeId, $addressType, $limit);

        return [
            'period' => $period->toArray(),
            'address_type' => $addressType,
            'limit' => $limit,
            'items' => array_map(
                fn (array $row): array => ['country_id' => $row['country_id'] ?? null]
                    + $this->projector->toTotals($row),
                $rows
            ),
        ];
    }
}
