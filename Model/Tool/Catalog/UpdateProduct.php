<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change an existing product.
 *
 * Only the fields named in the call are touched — this is a patch, not a
 * replace — because an agent that has to resend a whole product to change its
 * price will eventually resend it wrong.
 */
class UpdateProduct extends AbstractTool
{
    /**
     * Fields that map to a first-class setter or a plain attribute.
     */
    private const SIMPLE_FIELDS = [
        'name',
        'price',
        'status',
        'visibility',
        'weight',
        'description',
        'short_description',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'url_key',
        'special_price',
        'cost',
    ];

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductProjector $projector
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductProjector $projector,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update fields on an existing product. Only the fields you pass are changed. '
            . 'Pass store_code to set a store-view specific override instead of the default value. '
            . 'Passing null writes an empty override; it does not restore inheritance from the '
            . 'default scope — clear an override in the Magento admin with "Use Default Value".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $fields = [];
        foreach (self::SIMPLE_FIELDS as $field) {
            $fields[$field] = ['type' => ['string', 'number', 'null']];
        }
        $fields['status']['description'] = '1 = enabled, 2 = disabled.';
        $fields['visibility']['description'] = '1 = not visible, 2 = catalog, 3 = search, 4 = catalog & search.';

        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'sku' => ['type' => 'string', 'description' => 'Sku of the product to change.'],
                    'store_code' => $this->storeResolver->schemaProperty(),
                    'custom_attributes' => [
                        'type' => 'object',
                        'description' => 'Any other EAV attribute, by code. Advanced: values are passed '
                            . 'through to Magento unchanged, so a wrong option id fails the save.',
                    ],
                ],
                $fields
            ),
            'required' => ['sku'],
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $storeId = $this->storeResolver->resolve($this->optionalString($arguments, 'store_code'));

        try {
            // editMode = true so the product loads unfiltered for saving.
            $product = $this->productRepository->get($sku, true, $storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $changed = [];
        foreach (self::SIMPLE_FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $product->setData($field, $arguments[$field]);
            $changed[] = $field;
        }

        foreach ($this->optionalArray($arguments, 'custom_attributes') as $code => $value) {
            if (!is_string($code) || $code === '') {
                continue;
            }
            $product->setCustomAttribute($code, $value);
            $changed[] = $code;
        }

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides sku.'));
        }

        $product->setStoreId($storeId);
        $saved = $this->productRepository->save($product);

        return [
            'updated' => true,
            'sku' => $saved->getSku(),
            'store_id' => $storeId,
            'changed_fields' => array_values(array_unique($changed)),
            'product' => $this->projector->toSummary($saved),
        ];
    }
}
