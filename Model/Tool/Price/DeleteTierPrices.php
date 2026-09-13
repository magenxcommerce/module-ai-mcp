<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\TierPriceStorageInterface;

/**
 * Remove tier prices.
 */
class DeleteTierPrices extends AbstractTool
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
        return 'delete_tier_prices';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete specific tier prices, each named by sku, quantity and customer group. Call '
            . 'get_tier_prices first to see exactly which tiers exist; a row that matches no tier '
            . 'is reported by Magento rather than silently ignored.';
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
                    false,
                    'The tiers to delete. No price is needed — a tier is identified by its sku, '
                    . 'quantity, customer group and website.'
                ),
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
        $prices = $this->tierPrices->build($this->optionalArray($arguments, 'prices'), false);

        $failures = $this->tierPriceStorage->delete($prices);
        $this->tierPrices->assertNoFailures($failures, 'delete');

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'rows_deleted' => count($prices),
            'skus' => array_values(array_unique(array_map(
                static fn ($price): string => (string) $price->getSku(),
                $prices
            ))),
            'reindex_required' => true,
        ];
    }
}
