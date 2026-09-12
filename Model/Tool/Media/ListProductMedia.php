<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface as Entry;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read a product's images.
 */
class ListProductMedia extends AbstractTool
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
        return 'list_product_media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List a product\'s images with their gallery entry ids, file paths, positions, '
            . 'labels and roles. The roles are which of image, small_image, thumbnail and '
            . 'swatch_image each one fills. Read this before update_product_media or '
            . 'delete_product_media, both of which take an entry id.';
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

        try {
            $entries = $this->mediaGallery->getList($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        return [
            'sku' => $sku,
            'total_count' => count($entries),
            'items' => array_map(
                fn (Entry $entry): array => $this->projector->toArray($entry),
                array_values($entries)
            ),
        ];
    }
}
