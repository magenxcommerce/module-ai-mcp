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
 * Read one product, optionally as a given store view sees it.
 */
class GetProduct extends AbstractTool
{
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
        return 'get_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one product by sku, including stock, websites and category assignments. '
            . 'Pass store_code to see the values a particular store view resolves, '
            . 'and attributes to include specific EAV attributes such as description or meta_title.';
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
                'store_code' => $this->storeResolver->schemaProperty(),
                'attributes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Extra attribute codes to include, e.g. ["description", "meta_title"]. '
                        . 'Omitted by default because product attribute payloads are large.',
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
        return 'Magento_Catalog::products';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $storeId = $this->storeResolver->resolve($this->optionalString($arguments, 'store_code'));

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $extra = array_values(array_filter(
            $this->optionalArray($arguments, 'attributes'),
            'is_string'
        ));

        return $this->projector->toDetail($product, $extra);
    }
}
