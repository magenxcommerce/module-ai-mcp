<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Model\StoreResolver;
use Magento\Framework\Exception\LocalizedException;

/**
 * The period and scope arguments every report tool takes.
 *
 * Follows the `<Thing>Arguments` convention the module already uses for shared
 * argument handling — see `Model/Tool/Cms/PageContentArguments.php` — rather
 * than an abstract base, because no tool in this module calls a parent
 * constructor and a report tool should not be the first.
 */
class ReportArguments
{
    /** The grant these tools sit behind. Revenue is not the same read as an order. */
    public const ACL_RESOURCE = 'Magenx_AiMcp::reports';

    /** refund_basis: refunds counted against the orders placed in the period. */
    public const REFUND_BASIS_ORDER_DATE = 'order_date';

    /** refund_basis: refunds counted by the day the credit memo was issued. */
    public const REFUND_BASIS_REFUND_DATE = 'refund_date';

    /**
     * @param PeriodResolver $periodResolver
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly PeriodResolver $periodResolver,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @param bool $withGranularity
     * @return array<string, mixed>
     */
    public function schemaProperties(bool $withGranularity = false): array
    {
        return $this->periodResolver->schemaProperties($withGranularity) + [
            'store_code' => [
                'type' => 'string',
                'description' => 'Restrict to one store view. Omit to cover every store, which is '
                    . 'the usual question. The store view also decides the timezone the period is '
                    . 'read in. Call list_stores for the codes.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return int|null Null means every store.
     * @throws LocalizedException
     */
    public function storeId(array $arguments): ?int
    {
        $code = $arguments['store_code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        $storeId = $this->storeResolver->resolve(trim($code));
        if ($storeId === 0) {
            // The admin scope is not a storefront and no order carries its id,
            // so filtering on it returns an empty report rather than an error —
            // which reads as "no sales" instead of "wrong question".
            throw new LocalizedException(__(
                'The admin scope has no orders of its own. Omit store_code to report across every '
                . 'store, or call list_stores for a store view code.'
            ));
        }

        return $storeId;
    }

    /**
     * The compare_to and refund_basis arguments, for the tools that take them.
     *
     * @return array<string, mixed>
     */
    public function totalsOptionSchemaProperties(): array
    {
        return $this->periodResolver->comparisonSchemaProperties() + [
            'refund_basis' => [
                'type' => 'string',
                'enum' => [self::REFUND_BASIS_ORDER_DATE, self::REFUND_BASIS_REFUND_DATE],
                'description' => 'How refunds are dated. "order_date" (default): refunds made, at '
                    . 'any time, against orders placed in the period. "refund_date": credit memos '
                    . 'issued in the period, whatever order they belong to — what left the bank in '
                    . 'that period. Ordered and invoiced figures stay by order date either way.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string|null One of PeriodResolver::COMPARE_*, or null for no comparison.
     * @throws LocalizedException
     */
    public function compareTo(array $arguments): ?string
    {
        return $this->periodResolver->compareTo($arguments);
    }

    /**
     * @param Period $period
     * @param string $compareTo
     * @return Period
     */
    public function comparison(Period $period, string $compareTo): Period
    {
        return $this->periodResolver->comparison($period, $compareTo);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string One of the REFUND_BASIS_* constants.
     * @throws LocalizedException
     */
    public function refundBasis(array $arguments): string
    {
        $value = $arguments['refund_basis'] ?? null;
        if ($value === null || $value === '') {
            return self::REFUND_BASIS_ORDER_DATE;
        }

        if (!in_array($value, [self::REFUND_BASIS_ORDER_DATE, self::REFUND_BASIS_REFUND_DATE], true)) {
            throw new LocalizedException(
                __('The "refund_basis" argument must be "order_date" or "refund_date".')
            );
        }

        return $value;
    }

    /**
     * Schema for a `limit` argument.
     *
     * @param string $what What is being limited, for the description: "countries".
     * @param int $default
     * @param int $max
     * @return array<string, mixed>
     */
    public function limitSchema(string $what, int $default, int $max): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => $max,
                'description' => sprintf('How many %s to return (default %d, maximum %d).', $what, $default, $max),
            ],
        ];
    }

    /**
     * A whole-number argument clamped into range, or its default.
     *
     * Clamped rather than refused, the way page_size is everywhere else: a
     * model asking for 500 rows wants "as many as allowed", not an error.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    public function boundedInt(array $arguments, string $key, int $default, int $min, int $max): int
    {
        $value = $arguments[$key] ?? null;
        $value = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;

        return max($min, min($value, $max));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param int|null $storeId
     * @param bool $withGranularity
     * @return Period
     * @throws LocalizedException
     */
    public function period(array $arguments, ?int $storeId, bool $withGranularity = false): Period
    {
        return $this->periodResolver->resolve($arguments, $storeId, $withGranularity);
    }
}
