<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove an image from a product.
 */
class DeleteProductMedia extends AbstractTool
{
    /**
     * @param ProductAttributeMediaGalleryManagementInterface $mediaGallery
     * @param MediaEntryProjector $projector
     */
    public function __construct(
        private readonly ProductAttributeMediaGalleryManagementInterface $mediaGallery,
        private readonly MediaEntryProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one image from a product. This cannot be undone through this server, and '
            . 'deleting the image that holds a role leaves the product with no image for that '
            . 'role until another is given it. To take an image off the storefront reversibly, '
            . 'set disabled true with update_product_media instead.';
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
                'entry_id' => [
                    'type' => 'integer',
                    'description' => 'Gallery entry id, as list_product_media reports it.',
                ],
            ],
            'required' => ['sku', 'entry_id'],
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
        $entryId = $this->requireInt($arguments, 'entry_id');

        // Read first so the result names the image that is now gone, and so a
        // wrong entry id is refused before anything is removed.
        try {
            $entry = $this->mediaGallery->get($sku, $entryId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No image with entry id %1 exists on sku "%2". list_product_media reports the ids.',
                $entryId,
                $sku
            ));
        }

        $deleted = $this->projector->toArray($entry);

        $this->mediaGallery->remove($sku, $entryId);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'entry' => $deleted,
        ];
    }
}
