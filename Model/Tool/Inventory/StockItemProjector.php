<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Inventory;

use Magento\CatalogInventory\Api\Data\StockItemInterface;

/**
 * Presents a stock item so the "use config" flags cannot be missed.
 *
 * Magento stores most stock thresholds twice: the value, and a flag saying
 * whether to ignore it in favour of the store-wide default. A reader shown only
 * `min_qty: 0` cannot tell whether that zero is in force or whether the store
 * default applies, and an agent that then writes a threshold without clearing
 * the flag writes a value Magento never consults. So each of those settings is
 * reported as a pair: the stored value, and whether it is actually being used.
 */
class StockItemProjector
{
    /**
     * Settings that have a matching "use config" flag, as argument name =>
     * [value getter, flag getter].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CONFIGURABLE_SETTINGS = [
        'min_qty' => ['getMinQty', 'getUseConfigMinQty'],
        'min_sale_qty' => ['getMinSaleQty', 'getUseConfigMinSaleQty'],
        'max_sale_qty' => ['getMaxSaleQty', 'getUseConfigMaxSaleQty'],
        'backorders' => ['getBackorders', 'getUseConfigBackorders'],
        'notify_stock_qty' => ['getNotifyStockQty', 'getUseConfigNotifyStockQty'],
        'qty_increments' => ['getQtyIncrements', 'getUseConfigQtyIncrements'],
        'enable_qty_increments' => ['getEnableQtyIncrements', 'getUseConfigEnableQtyInc'],
        'manage_stock' => ['getManageStock', 'getUseConfigManageStock'],
    ];

    /**
     * @param StockItemInterface $item
     * @param string|null $sku
     * @return array<string, mixed>
     */
    public function toArray(StockItemInterface $item, ?string $sku = null): array
    {
        $projection = [
            'sku' => $sku,
            'product_id' => $item->getProductId() === null ? null : (int) $item->getProductId(),
            'stock_item_id' => $item->getItemId() === null ? null : (int) $item->getItemId(),
            'qty' => $item->getQty() === null ? null : (float) $item->getQty(),
            'is_in_stock' => (bool) $item->getIsInStock(),
            'is_qty_decimal' => (bool) $item->getIsQtyDecimal(),
        ];

        foreach (self::CONFIGURABLE_SETTINGS as $name => [$valueGetter, $flagGetter]) {
            $usesConfig = (bool) $item->{$flagGetter}();
            $value = $item->{$valueGetter}();
            $projection[$name] = [
                'value' => $value === null ? null : $this->scalar($value),
                // True means the stored value above is ignored and the store's
                // default applies instead.
                'uses_config_default' => $usesConfig,
            ];
        }

        return $projection;
    }

    /**
     * Magento returns these columns as strings; booleans among them are 0/1.
     *
     * @param string|int|float|bool $value
     * @return float|bool
     */
    private function scalar(string|int|float|bool $value): float|bool
    {
        return is_bool($value) ? $value : (float) $value;
    }
}
