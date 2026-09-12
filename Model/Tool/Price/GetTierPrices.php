<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\TierPriceInterface;
use Magento\Catalog\Api\TierPriceStorageInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Read the tier prices of one or more products.
 */
class GetTierPrices extends AbstractTool
{
    /** More than this in one call and the response stops being readable. */
    private const MAX_SKUS = 50;

    /**
     * @param TierPriceStorageInterface $tierPriceStorage
     */
    public function __construct(
        private readonly TierPriceStorageInterface $tierPriceStorage
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_tier_prices';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the quantity-based tier prices of the given skus. A sku with no tier prices '
            . 'simply returns no rows. Read this before set_tier_prices so an existing tier is '
            . 'changed rather than left alongside a new one.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'description' => 'Product skus to read, at most ' . self::MAX_SKUS . ' per call.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['skus'],
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
    public function execute(array $arguments): array
    {
        $skus = [];
        foreach ($this->optionalArray($arguments, 'skus') as $sku) {
            if (!is_string($sku) || trim($sku) === '') {
                throw new LocalizedException(__('Every entry in "skus" must be a sku.'));
            }
            $skus[] = trim($sku);
        }
        $skus = array_values(array_unique($skus));

        if ($skus === []) {
            throw new LocalizedException(__('Pass at least one sku in "skus".'));
        }
        if (count($skus) > self::MAX_SKUS) {
            throw new LocalizedException(
                __('Too many skus: %1 given, %2 at most per call.', count($skus), self::MAX_SKUS)
            );
        }

        $prices = $this->tierPriceStorage->get($skus);

        return [
            'skus' => $skus,
            'total_count' => count($prices),
            'items' => array_map(
                static fn (TierPriceInterface $price): array => [
                    'sku' => $price->getSku(),
                    'quantity' => $price->getQuantity() === null ? null : (float) $price->getQuantity(),
                    'price' => $price->getPrice() === null ? null : (float) $price->getPrice(),
                    'price_type' => $price->getPriceType(),
                    'customer_group' => $price->getCustomerGroup(),
                    'website_id' => $price->getWebsiteId() === null ? null : (int) $price->getWebsiteId(),
                ],
                array_values($prices)
            ),
        ];
    }
}
