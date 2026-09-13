<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog\Option;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read one product's custom options.
 */
class ListProductOptions extends AbstractTool
{
    /**
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     * @param ProductRepositoryInterface $productRepository
     * @param CustomOptionProjector $projector
     */
    public function __construct(
        private readonly ProductCustomOptionRepositoryInterface $optionRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomOptionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_product_options';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a product\'s custom options — the per-product choices a customer makes when '
            . 'buying, such as an engraving text or a gift-wrap select. These belong to the one '
            . 'product and are not attributes: an attribute is catalogue-wide and set with '
            . 'create_product_attribute, a custom option exists only here.';
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

        // The option service answers for an unknown sku with an empty list,
        // which reads the same as a product that simply has no options.
        try {
            $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        return [
            'sku' => $sku,
            'options' => array_map(
                fn (ProductCustomOptionInterface $option): array => $this->projector->toArray($option),
                array_values($this->optionRepository->getList($sku))
            ),
        ];
    }
}
