<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\MediaGallery;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaGalleryApi\Api\Data\AssetInterface;
use Magento\MediaGalleryApi\Api\SearchAssetsInterface;

/**
 * Find files in the media gallery — the images CMS content and widgets point at.
 */
class SearchMediaGalleryAssets extends AbstractTool
{
    /**
     * @param SearchAssetsInterface $searchAssets
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly SearchAssetsInterface $searchAssets,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_media_gallery_assets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find files in the media gallery by path or title — the images CMS pages, blocks '
            . 'and widgets reference, as distinct from product images, which list_product_media '
            . 'reports. At least one filter is required, because an unfiltered lookup returns '
            . 'every asset in the store.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Match anywhere in the media-relative path, e.g. '
                            . '"wysiwyg/banners". Partial match.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Match anywhere in the asset title. Partial match.',
                    ],
                    'content_type' => [
                        'type' => 'string',
                        'description' => 'Exact mime type, e.g. "image/png".',
                    ],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cms::media_gallery';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $filtered = false;
        foreach (['path' => 'like', 'title' => 'like', 'content_type' => 'eq'] as $field => $condition) {
            $value = $this->optionalString($arguments, $field);
            if ($value === null) {
                continue;
            }
            $this->searchCriteriaBuilder->addFilter(
                $field,
                $condition === 'like' ? '%' . $value . '%' : $value,
                $condition
            );
            $filtered = true;
        }

        if (!$filtered) {
            throw new LocalizedException(__(
                'Pass at least one of path, title or content_type. An unfiltered lookup returns '
                . 'every asset in the store.'
            ));
        }

        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $assets = array_values($this->searchAssets->execute($this->searchCriteriaBuilder->create()));

        return [
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (AssetInterface $asset): array => [
                    'asset_id' => $asset->getId() === null ? null : (int) $asset->getId(),
                    'path' => $asset->getPath(),
                    'title' => $asset->getTitle(),
                    'content_type' => $asset->getContentType(),
                    'width' => (int) $asset->getWidth(),
                    'height' => (int) $asset->getHeight(),
                    'size' => (int) $asset->getSize(),
                    'created_at' => $asset->getCreatedAt(),
                    'updated_at' => $asset->getUpdatedAt(),
                ],
                $assets
            ),
        ];
    }
}
