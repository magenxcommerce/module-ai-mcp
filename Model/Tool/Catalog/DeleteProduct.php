<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Delete a product.
 */
class DeleteProduct extends AbstractTool
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductProjector $projector
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a product. This cannot be undone, and it takes the product\'s '
            . 'images, tier prices, links and category assignments with it. Past orders keep their '
            . 'own copy of the line and are unaffected. Deleting a simple that is a variant of a '
            . 'configurable removes that variant. To take a product off the storefront reversibly, '
            . 'set status 2 with update_product, or unassign it from its website.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Sku of the product to delete.'],
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

        // Read first so the result names what is now gone, and so a wrong sku
        // is refused rather than reported as a successful delete.
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $deleted = $this->projector->toSummary($product);

        $this->productRepository->delete($product);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'reindex_required' => true,
        ] + $deleted;
    }
}
