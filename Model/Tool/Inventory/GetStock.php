<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Inventory;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read a product's stock item.
 */
class GetStock extends AbstractTool
{
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
        return 'get_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the stock record for one sku: quantity, in-stock flag, and the backorder and '
            . 'threshold settings. Each threshold is reported as a value plus '
            . 'uses_config_default, which says whether that value is in force or the store-wide '
            . 'default applies instead. Call this before update_stock rather than assuming the '
            . 'current quantity.';
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
                    'description' => 'Website whose stock to read. Omit for the default stock, '
                        . 'which is the only one on a single-website store.',
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
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $websiteId = $this->optionalInt($arguments, 'website_id');

        try {
            $item = $this->stockRegistry->getStockItemBySku($sku, $websiteId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        // A sku with no stock row of its own comes back as an empty item rather
        // than an error, which would otherwise read as "quantity zero".
        if ($item->getItemId() === null) {
            throw new LocalizedException(__(
                'No stock record exists for sku "%1". A product of a type Magento does not track '
                . 'stock for, such as a downloadable or a configurable parent, has none.',
                $sku
            ));
        }

        $projection = $this->projector->toArray($item, $sku);
        $projection['stock_status_is_salable'] = (bool) $this->stockRegistry
            ->getStockStatusBySku($sku, $websiteId)
            ->getStockStatus();

        return $projection;
    }
}
