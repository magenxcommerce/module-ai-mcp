<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * Carts left idle with something in them: how many, how much, and the biggest.
 *
 * Personal data, because the list carries the shopper's e-mail address — the
 * one thing that makes an abandoned cart recoverable.
 */
class AbandonedCarts extends AbstractTool implements PersonalDataToolInterface
{
    private const DEFAULT_INACTIVITY_HOURS = 24;
    private const MAX_INACTIVITY_HOURS = 8760;
    private const DEFAULT_MAX_AGE_DAYS = 30;
    private const MAX_MAX_AGE_DAYS = 365;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /**
     * @param ReportArguments $arguments
     * @param CartAggregator $aggregator
     * @param CoreDate $date
     */
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly CartAggregator $aggregator,
        private readonly CoreDate $date
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'abandoned_carts';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Abandoned carts: still open, with items, untouched for at least inactivity_hours and '
            . 'at most max_age_days. Returns count, subtotal and items per base currency, how many '
            . 'have an e-mail address, and the most valuable carts with their shopper\'s e-mail. '
            . 'Call get_cart for one cart\'s lines.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'inactivity_hours' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_INACTIVITY_HOURS,
                    'description' => 'Hours since the cart was last changed. Defaults to '
                        . self::DEFAULT_INACTIVITY_HOURS . '.',
                ],
                'max_age_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_MAX_AGE_DAYS,
                    'description' => 'Ignore carts last changed longer ago than this. Defaults to '
                        . self::DEFAULT_MAX_AGE_DAYS . '.',
                ],
                'has_email' => [
                    'type' => 'boolean',
                    'description' => 'true for only carts with an e-mail address to follow up on, '
                        . 'false for only those without. Omit for both.',
                ],
                'store_code' => $this->arguments->schemaProperties()['store_code'],
            ] + $this->arguments->limitSchema('carts', self::DEFAULT_LIMIT, self::MAX_LIMIT),
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
        $hours = $this->arguments->boundedInt(
            $arguments,
            'inactivity_hours',
            self::DEFAULT_INACTIVITY_HOURS,
            1,
            self::MAX_INACTIVITY_HOURS
        );
        $maxAge = $this->arguments->boundedInt(
            $arguments,
            'max_age_days',
            self::DEFAULT_MAX_AGE_DAYS,
            1,
            self::MAX_MAX_AGE_DAYS
        );
        $hasEmail = $this->optionalBool($arguments, 'has_email');
        $limit = $this->arguments->boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $now = (int) $this->date->gmtTimestamp();
        $idleSince = gmdate('Y-m-d H:i:s', $now - $hours * 3600);
        $notBefore = gmdate('Y-m-d H:i:s', $now - $maxAge * 86400);

        return [
            'idle_since_utc' => $idleSince,
            'not_before_utc' => $notBefore,
            'limit' => $limit,
            'totals' => array_map(
                static fn (array $row): array => [
                    'currency' => $row['currency'] ?? null,
                    'carts' => (int) ($row['carts'] ?? 0),
                    'subtotal' => (float) ($row['subtotal'] ?? 0),
                    'items' => (float) ($row['items'] ?? 0),
                    'with_email' => (int) ($row['with_email'] ?? 0),
                    'registered' => (int) ($row['registered'] ?? 0),
                ],
                $this->aggregator->summary($idleSince, $notBefore, $storeId, $hasEmail)
            ),
            'items' => array_map(
                static fn (array $row): array => [
                    'cart_id' => (int) ($row['cart_id'] ?? 0),
                    'store_id' => (int) ($row['store_id'] ?? 0),
                    'customer_id' => isset($row['customer_id']) ? (int) $row['customer_id'] : null,
                    'customer_email' => $row['customer_email'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'items_count' => (int) ($row['items_count'] ?? 0),
                    'items_qty' => (float) ($row['items_qty'] ?? 0),
                    'subtotal' => (float) ($row['subtotal'] ?? 0),
                    'currency' => $row['currency'] ?? null,
                ],
                $this->aggregator->carts($idleSince, $notBefore, $storeId, $hasEmail, $limit)
            ),
        ];
    }
}
