<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Inventory;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change a product's quantity, stock state and thresholds.
 */
class UpdateStock extends AbstractTool
{
    /**
     * Settings whose stored value Magento ignores while their "use config" flag
     * is set, as argument name => [value setter, flag setter].
     *
     * Writing one of these without clearing its flag stores a number the store
     * never consults, which looks like a successful change that did nothing. So
     * setting any of them clears its flag in the same save, unless the caller
     * explicitly asked to go back to the store default.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CONFIGURABLE_SETTINGS = [
        'manage_stock' => ['setManageStock', 'setUseConfigManageStock'],
        'min_qty' => ['setMinQty', 'setUseConfigMinQty'],
        'min_sale_qty' => ['setMinSaleQty', 'setUseConfigMinSaleQty'],
        'max_sale_qty' => ['setMaxSaleQty', 'setUseConfigMaxSaleQty'],
        'backorders' => ['setBackorders', 'setUseConfigBackorders'],
        'notify_stock_qty' => ['setNotifyStockQty', 'setUseConfigNotifyStockQty'],
        'qty_increments' => ['setQtyIncrements', 'setUseConfigQtyIncrements'],
        'enable_qty_increments' => ['setEnableQtyIncrements', 'setUseConfigEnableQtyInc'],
    ];

    /**
     * @param StockRegistryInterface $stockRegistry
     * @param StockItemProjector $projector
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockItemProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set a product\'s quantity, in-stock flag, backorder mode or thresholds. Only the '
            . 'fields you pass are changed, and qty replaces the current quantity rather than '
            . 'adding to it — read get_stock first if you mean to adjust it. Setting a threshold '
            . 'also stops that setting inheriting the store default; pass it as null to go back to '
            . 'inheriting. Stock changes are reflected on the storefront only after the stock '
            . 'indexer runs, which invalidate_indexers can trigger.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Product sku.'],
                'website_id' => [
                    'type' => 'integer',
                    'description' => 'Website whose stock to change. Omit for the default stock, '
                        . 'which is the only one on a single-website store.',
                ],
                'qty' => [
                    'type' => 'number',
                    'description' => 'The new quantity, replacing the current one. Not a delta.',
                ],
                'is_in_stock' => [
                    'type' => 'boolean',
                    'description' => 'Whether the product is sellable. Magento does not set this '
                        . 'from qty on its own, so a product left in stock at qty 0 stays '
                        . 'orderable only if backorders allow it.',
                ],
                'manage_stock' => [
                    'type' => ['boolean', 'null'],
                    'description' => 'Whether Magento tracks quantity for this product at all. '
                        . 'Null restores the store default.',
                ],
                'backorders' => [
                    'type' => ['integer', 'null'],
                    'description' => '0 = no backorders, 1 = allow, 2 = allow and notify the '
                        . 'customer. Null restores the store default.',
                ],
                'min_qty' => [
                    'type' => ['number', 'null'],
                    'description' => 'Quantity at which the product goes out of stock, normally 0. '
                        . 'Null restores the store default.',
                ],
                'min_sale_qty' => [
                    'type' => ['number', 'null'],
                    'description' => 'Smallest quantity one order may contain. Null restores the '
                        . 'store default.',
                ],
                'max_sale_qty' => [
                    'type' => ['number', 'null'],
                    'description' => 'Largest quantity one order may contain. Null restores the '
                        . 'store default.',
                ],
                'notify_stock_qty' => [
                    'type' => ['number', 'null'],
                    'description' => 'Quantity below which the product counts as low stock, which '
                        . 'is what search_low_stock reports against. Null restores the store default.',
                ],
                'qty_increments' => [
                    'type' => ['number', 'null'],
                    'description' => 'Quantity must be a multiple of this. Null restores the store '
                        . 'default.',
                ],
                'enable_qty_increments' => [
                    'type' => ['boolean', 'null'],
                    'description' => 'Whether qty_increments is enforced. Null restores the store '
                        . 'default.',
                ],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_CatalogInventory::cataloginventory';
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
    protected function isIdempotent(): bool
    {
        // Quantity is set to the value given rather than adjusted by it, so a
        // repeat lands on the same number.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $websiteId = $this->optionalInt($arguments, 'website_id');

        // The existing row is loaded and mutated so that a setting the caller
        // did not mention keeps both its value and its inheritance flag.
        try {
            $item = $this->stockRegistry->getStockItemBySku($sku, $websiteId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        if ($item->getItemId() === null) {
            throw new LocalizedException(__(
                'No stock record exists for sku "%1". A product of a type Magento does not track '
                . 'stock for, such as a downloadable or a configurable parent, has none.',
                $sku
            ));
        }

        $changed = [];

        if (array_key_exists('qty', $arguments)) {
            $item->setQty($this->requireNumber($arguments, 'qty'));
            $changed[] = 'qty';
        }

        if (array_key_exists('is_in_stock', $arguments)) {
            $isInStock = $this->optionalBool($arguments, 'is_in_stock');
            if ($isInStock === null) {
                throw new LocalizedException(__('The "is_in_stock" argument must be true or false.'));
            }
            $item->setIsInStock($isInStock);
            $changed[] = 'is_in_stock';
        }

        $changed = array_merge($changed, $this->applyConfigurableSettings($item, $arguments));

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides sku.'));
        }

        $this->stockRegistry->updateStockItemBySku($sku, $item);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'reindex_required' => true,
        ] + $this->projector->toArray($this->stockRegistry->getStockItemBySku($sku, $websiteId), $sku);
    }

    /**
     * Apply the settings that carry a "use config" flag.
     *
     * @param StockItemInterface $item
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function applyConfigurableSettings(StockItemInterface $item, array $arguments): array
    {
        $changed = [];

        foreach (self::CONFIGURABLE_SETTINGS as $name => [$valueSetter, $flagSetter]) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            if ($arguments[$name] === null) {
                // Back to inheriting: the stored value is left as it was, since
                // Magento stops consulting it once the flag is set.
                $item->{$flagSetter}(true);
                $changed[] = $name . ' (restored to store default)';
                continue;
            }

            $value = $this->settingValue($name, $arguments[$name]);
            $item->{$valueSetter}($value);
            $item->{$flagSetter}(false);
            $changed[] = $name;
        }

        return $changed;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool|float|int
     * @throws LocalizedException
     */
    private function settingValue(string $name, mixed $value): bool|float|int
    {
        if (in_array($name, ['manage_stock', 'enable_qty_increments'], true)) {
            if (!is_bool($value)) {
                throw new LocalizedException(
                    __('The "%1" argument must be true, false or null.', $name)
                );
            }

            return $value;
        }

        if ($name === 'backorders') {
            if (!is_int($value) || !in_array($value, [0, 1, 2], true)) {
                throw new LocalizedException(
                    __('The "backorders" argument must be 0, 1 or 2, or null to use the store default.')
                );
            }

            return $value;
        }

        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('The "%1" argument must be a number.', $name));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return float
     * @throws LocalizedException
     */
    private function requireNumber(array $arguments, string $key): float
    {
        $value = $arguments[$key] ?? null;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('The "%1" argument must be a number.', $key));
        }

        return (float) $value;
    }
}
