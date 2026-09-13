<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\TierPriceStorageInterface;

/**
 * Add or change tier prices.
 */
class SetTierPrices extends AbstractTool
{
    /**
     * @param TierPriceStorageInterface $tierPriceStorage
     * @param TierPriceArguments $tierPrices
     */
    public function __construct(
        private readonly TierPriceStorageInterface $tierPriceStorage,
        private readonly TierPriceArguments $tierPrices
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_tier_prices';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add or change tier prices. A row replaces the existing tier for the same sku, '
            . 'quantity and customer group, and is added when there is none; tiers you do not '
            . 'mention are left alone. Set replace_all to true to instead make these rows the '
            . 'complete set of tier prices for every sku mentioned, deleting any other tier those '
            . 'skus have. Magento validates each row and applies nothing if any is rejected.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prices' => $this->tierPrices->schemaProperty(
                    true,
                    'The tier prices to set.'
                ),
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Treat these rows as the complete set of tier prices for the '
                        . 'skus they mention, deleting any tier of those skus that is not listed. '
                        . 'Default false, which only adds and changes.',
                ],
            ],
            'required' => ['prices'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::products';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $prices = $this->tierPrices->build($this->optionalArray($arguments, 'prices'), true);
        $replaceAll = (bool) $this->optionalBool($arguments, 'replace_all', false);

        $failures = $replaceAll
            ? $this->tierPriceStorage->replace($prices)
            : $this->tierPriceStorage->update($prices);

        // The storage reports rejected rows by returning them rather than
        // throwing, so this is what stands between a rejected price and a
        // result that claims success.
        $this->tierPrices->assertNoFailures($failures, $replaceAll ? 'replace' : 'update');

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'mode' => $replaceAll ? 'replace' : 'update',
            'rows_applied' => count($prices),
            'skus' => array_values(array_unique(array_map(
                static fn ($price): string => (string) $price->getSku(),
                $prices
            ))),
            'reindex_required' => true,
        ];
    }
}
