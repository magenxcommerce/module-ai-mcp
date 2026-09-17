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
