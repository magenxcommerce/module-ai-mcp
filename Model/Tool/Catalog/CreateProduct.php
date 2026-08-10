<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Create a product in the default scope.
 *
 * Deliberately limited to the fields a product needs to exist and be findable;
 * anything further is an update_product call, which keeps this tool's failure
 * modes few and legible.
 */
class CreateProduct extends AbstractTool
{
    /**
     * @param ProductInterfaceFactory $productFactory
     * @param ProductRepositoryInterface $productRepository
     * @param ProductProjector $projector
     * @param StoreManagerInterface $storeManager
     * @param StockItemInterfaceFactory $stockItemFactory
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductProjector $projector,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockItemInterfaceFactory $stockItemFactory,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a new product in the default scope. Returns the created product. '
            . 'Set further attributes, store-view overrides or category assignments '
            . 'with update_product and assign_product_to_category afterwards.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Unique sku.'],
                'name' => ['type' => 'string'],
                'price' => ['type' => 'number'],
                'type_id' => [
                    'type' => 'string',
                    'description' => 'Product type, default "simple".',
                    'enum' => [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL, Type::TYPE_BUNDLE, 'configurable', 'grouped'],
                ],
                'attribute_set_id' => [
                    'type' => 'integer',
                    'description' => 'Defaults to the Default attribute set. Use list_attribute_sets to choose another.',
                ],
                'status' => ['type' => 'integer', 'description' => '1 = enabled (default), 2 = disabled.'],
                'visibility' => [
                    'type' => 'integer',
                    'description' => '4 = catalog & search (default), 1 = not visible individually.',
                ],
                'weight' => ['type' => 'number'],
                'qty' => ['type' => 'number', 'description' => 'Initial stock quantity. Defaults to 0.'],
                'is_in_stock' => ['type' => 'boolean', 'description' => 'Defaults to true when qty is above zero.'],
                'website_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Websites to assign. Defaults to the default website.',
                ],
            ],
            'required' => ['sku', 'name', 'price'],
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
        $sku = $this->requireString($arguments, 'sku');
        $name = $this->requireString($arguments, 'name');
        $price = $arguments['price'] ?? null;
        if (!is_int($price) && !is_float($price) && !(is_string($price) && is_numeric($price))) {
            throw new LocalizedException(__('The "price" argument must be a number.'));
        }

        if ($this->exists($sku)) {
            throw new LocalizedException(
                __('A product with sku "%1" already exists. Use update_product to change it.', $sku)
            );
        }

        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setName($name);
        $product->setPrice((float) $price);
        $product->setTypeId($this->optionalString($arguments, 'type_id', Type::TYPE_SIMPLE));
        $product->setStatus($this->optionalInt($arguments, 'status') ?? Status::STATUS_ENABLED);
        $product->setVisibility($this->optionalInt($arguments, 'visibility') ?? Visibility::VISIBILITY_BOTH);
        $product->setAttributeSetId(
            $this->optionalInt($arguments, 'attribute_set_id') ?? $this->defaultAttributeSetId()
        );

        if (array_key_exists('weight', $arguments) && is_numeric($arguments['weight'])) {
            $product->setWeight((float) $arguments['weight']);
        }

        $websiteIds = array_map('intval', array_filter($this->optionalArray($arguments, 'website_ids'), 'is_numeric'));
        if ($websiteIds === []) {
            $websiteIds = [(int) $this->storeManager->getWebsite()->getId()];
        }
        $qty = isset($arguments['qty']) && is_numeric($arguments['qty']) ? (float) $arguments['qty'] : 0.0;
        $inStock = (bool) ($arguments['is_in_stock'] ?? ($qty > 0));

        // Websites and the initial stock item both travel as extension
        // attributes; a new product has no stock item yet, so build one.
        $extension = $product->getExtensionAttributes();
        if ($extension !== null) {
            if (method_exists($extension, 'setWebsiteIds')) {
                $extension->setWebsiteIds($websiteIds);
            }
            $stockItem = $this->stockItemFactory->create();
            $stockItem->setQty($qty);
            $stockItem->setIsInStock($inStock);
            $extension->setStockItem($stockItem);
            $product->setExtensionAttributes($extension);
        }

        $saved = $this->productRepository->save($product);

        return [
            'created' => true,
            'sku' => $saved->getSku(),
            'product' => $this->projector->toSummary($saved),
        ];
    }

    /**
     * @param string $sku
     * @return bool
     */
    private function exists(string $sku): bool
    {
        try {
            $this->productRepository->get($sku);

            return true;
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return false;
        }
    }

    /**
     * The Default attribute set of the Catalog Product entity.
     *
     * @return int
     * @throws LocalizedException
     */
    private function defaultAttributeSetId(): int
    {
        return (int) $this->eavConfig
            ->getEntityType(\Magento\Catalog\Model\Product::ENTITY)
            ->getDefaultAttributeSetId();
    }
}
