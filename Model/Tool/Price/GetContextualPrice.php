<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * "What does a wholesale customer pay for this, in that store, for ten?"
 */
class GetContextualPrice extends AbstractTool
{
    private const PRICE_INDEXER = 'catalog_product_price';

    /**
     * @param ProductResource $productResource
     * @param StoreResolver $storeResolver
     * @param StoreRepositoryInterface $storeRepository
     * @param TimezoneInterface $timezone
     * @param IndexerRegistry $indexerRegistry
     * @param ContextualPriceReader $reader
     */
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly StoreResolver $storeResolver,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly TimezoneInterface $timezone,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ContextualPriceReader $reader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_contextual_price';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'The price one customer group pays for a product on a store view, as the storefront '
            . 'computes it: indexed regular and final price, today\'s catalog rule price, the tier '
            . 'price for the quantity, and the resulting unit price. Reports the price indexer\'s '
            . 'state, since a stale index is the price shoppers see. Base currency of the website.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The product.'],
                'customer_group_id' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => '0 is NOT LOGGED IN; list_customer_groups has the others.',
                ],
                'store_code' => [
                    'type' => 'string',
                    'description' => 'The store view; its website decides the price scope and its '
                        . 'timezone decides "today" for catalog rules. Call list_stores for the codes.',
                ],
                'qty' => [
                    'type' => 'number',
                    'exclusiveMinimum' => 0,
                    'description' => 'Quantity, for tier prices. Defaults to 1.',
                ],
            ],
            'required' => ['sku', 'customer_group_id', 'store_code'],
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
        $sku = $this->requireString($arguments, 'sku');
        $groupId = $this->requireInt($arguments, 'customer_group_id');
        $qty = $this->qty($arguments);

        $storeId = $this->storeResolver->resolve($this->requireString($arguments, 'store_code'));
        if ($storeId === 0) {
            throw new LocalizedException(
                __('The admin scope has no prices of its own. Pass a store view code; list_stores has them.')
            );
        }
        $websiteId = (int) $this->storeRepository->getById($storeId)->getWebsiteId();

        $productId = (int) $this->productResource->getIdBySku($sku);
        if ($productId === 0) {
            throw new LocalizedException(__('There is no product with sku "%1".', $sku));
        }

        $today = $this->timezone->scopeDate($storeId)->format('Y-m-d');
        $index = $this->reader->indexedPrice($productId, $websiteId, $groupId);
        $rulePrice = $this->reader->rulePrice($productId, $websiteId, $groupId, $today);
        $regular = $index === null ? null : (float) $index['price'];
        $tierPrice = $this->bestTier($this->reader->tierPrices($productId, $websiteId, $groupId, $qty), $regular);
        $final = $index === null ? null : (float) $index['final_price'];

        $unit = $final;
        if ($unit !== null && $tierPrice !== null) {
            $unit = min($unit, $tierPrice);
        }

        $indexer = $this->indexerRegistry->get(self::PRICE_INDEXER);

        return [
            'sku' => $sku,
            'product_id' => $productId,
            'store_id' => $storeId,
            'website_id' => $websiteId,
            'customer_group_id' => $groupId,
            'qty' => $qty,
            'price_index' => $index === null ? null : [
                'price' => $regular,
                'final_price' => $final,
                'min_price' => (float) $index['min_price'],
                'max_price' => (float) $index['max_price'],
                'lowest_tier_price' => $index['tier_price'] === null ? null : (float) $index['tier_price'],
            ],
            'catalog_rule_price' => $rulePrice,
            'catalog_rule_date' => $today,
            'tier_price_for_qty' => $tierPrice,
            'unit_price' => $unit,
            'price_index_status' => [
                'valid' => (bool) $indexer->isValid(),
                'status' => $indexer->getStatus(),
                'scheduled' => (bool) $indexer->isScheduled(),
            ],
            'note' => $index === null
                ? 'The price index has no row for this product, website and group: it is not '
                    . 'assigned to the website, is disabled or not visible, or has not been indexed yet.'
                : null,
        ];
    }

    /**
     * The lowest unit price any applicable tier gives.
     *
     * A percentage tier is a discount off the regular price, so it needs that
     * price to mean anything.
     *
     * @param array<int, array<string, mixed>> $tiers
     * @param float|null $regular
     * @return float|null
     */
    private function bestTier(array $tiers, ?float $regular): ?float
    {
        $best = null;
        foreach ($tiers as $tier) {
            $percentage = $tier['percentage_value'] ?? null;
            if ($percentage !== null && (float) $percentage > 0) {
                if ($regular === null) {
                    continue;
                }
                $price = round($regular * (1 - (float) $percentage / 100), 4);
            } else {
                $price = (float) ($tier['value'] ?? 0);
            }
            $best = $best === null ? $price : min($best, $price);
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return float
     * @throws LocalizedException
     */
    private function qty(array $arguments): float
    {
        $value = $arguments['qty'] ?? 1;
        if (!(is_int($value) || is_float($value)) || $value <= 0) {
            throw new LocalizedException(__('The "qty" argument must be a number above 0.'));
        }

        return (float) $value;
    }
}
