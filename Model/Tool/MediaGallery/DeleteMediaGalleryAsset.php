<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\MediaGallery;

use Magenx\AiMcp\Model\MediaPathPolicy;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaContentApi\Api\Data\ContentIdentityInterface;
use Magento\MediaContentApi\Api\GetContentByAssetIdsInterface;
use Magento\MediaGalleryApi\Api\DeleteAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;

/**
 * Remove a file from the media gallery, unless something is still using it.
 *
 * Deleting an asset deletes the file off disk, and every page, block or product
 * description pointing at it keeps pointing at it — the reference survives, the
 * image does not, and the result is a broken image on the storefront with
 * nothing anywhere reporting an error. Magento's own admin warns about this and
 * then lets it happen.
 *
 * So this refuses instead, and names what is holding the asset: the entity type
 * and id of every piece of content that references it. That turns a silent
 * breakage into a list of things to fix first.
 *
 * Two boundaries, both deliberate:
 *
 *  - Only paths under the CMS gallery root can be deleted, checked with
 *    {@see MediaPathPolicy::segments()} — the same guard uploads pass, so `..`,
 *    a leading `/`, a backslash and a null byte are all refused here too.
 *    Product images live elsewhere and have their own tool,
 *    delete_product_media, which keeps the product's gallery consistent.
 *  - The asset is addressed by path rather than by id, because
 *    search_media_gallery_assets reports `asset_id` as nullable while `path` is
 *    always there.
 */
class DeleteMediaGalleryAsset extends AbstractTool
{
    /** Enough to act on without returning an unbounded list. */
    private const MAX_USAGES_REPORTED = 20;

    /**
     * @param MediaPathPolicy $pathPolicy
     * @param GetAssetsByPathsInterface $getAssetsByPaths
     * @param GetContentByAssetIdsInterface $getContentByAssetIds
     * @param DeleteAssetsByPathsInterface $deleteAssetsByPaths
     */
    public function __construct(
        private readonly MediaPathPolicy $pathPolicy,
        private readonly GetAssetsByPathsInterface $getAssetsByPaths,
        private readonly GetContentByAssetIdsInterface $getContentByAssetIds,
        private readonly DeleteAssetsByPathsInterface $deleteAssetsByPaths
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_media_gallery_asset';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a file from the media gallery, by its media-relative path as '
            . 'search_media_gallery_assets reports it. Refused if any CMS page, block or product '
            . 'still references the image, naming what does — deleting it would leave those pages '
            . 'with a broken image and no error anywhere. Only paths under "'
            . MediaPathPolicy::ROOT . '/" can be deleted; product images are removed with '
            . 'delete_product_media instead. This cannot be undone.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Media-relative path of the asset, e.g. "'
                        . MediaPathPolicy::ROOT . '/banners/hero.jpg", exactly as '
                        . 'search_media_gallery_assets reports it.',
                ],
            ],
            'required' => ['path'],
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
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $path = $this->assertDeletablePath($this->requireString($arguments, 'path'));

        $assets = $this->getAssetsByPaths->execute([$path]);
        if ($assets === []) {
            throw new LocalizedException(__(
                'No media gallery asset exists at "%1". Use search_media_gallery_assets to find '
                . 'the exact path.',
                $path
            ));
        }

        $asset = reset($assets);
        $assetId = $asset->getId() === null ? null : (int) $asset->getId();

        // A null id means the file is on disk but not indexed, so nothing can
        // be referencing it through the media content tables either.
        if ($assetId !== null) {
            $this->assertUnused($assetId, $path);
        }

        $this->deleteAssetsByPaths->execute([$path]);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'path' => $path,
            'asset_id' => $assetId,
            'title' => $asset->getTitle(),
        ];
    }

    /**
     * Confine the delete to the CMS gallery root, using the upload guard.
     *
     * @param string $path
     * @return string
     * @throws LocalizedException
     */
    private function assertDeletablePath(string $path): string
    {
        $segments = $this->pathPolicy->segments($path, 'path');

        if (count($segments) < 2 || $segments[0] !== MediaPathPolicy::ROOT) {
            throw new LocalizedException(__(
                'The "path" argument must name a file under "%1/", as search_media_gallery_assets '
                . 'reports it. Product images are outside it and are removed with '
                . 'delete_product_media, which also keeps the product\'s gallery consistent.',
                MediaPathPolicy::ROOT
            ));
        }

        return implode('/', $segments);
    }

    /**
     * @param int $assetId
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    private function assertUnused(int $assetId, string $path): void
    {
        $identities = $this->getContentByAssetIds->execute([$assetId]);
        if ($identities === []) {
            return;
        }

        $described = [];
        foreach ($identities as $identity) {
            if (count($described) >= self::MAX_USAGES_REPORTED) {
                break;
            }
            $described[] = $this->describe($identity);
        }

        $more = count($identities) - count($described);

        throw new LocalizedException(__(
            '"%1" is still used by %2 item(s): %3.%4 Deleting it would leave them showing a broken '
            . 'image with no error reported anywhere. Remove the image from those first, then '
            . 'delete the asset.',
            $path,
            count($identities),
            implode(', ', $described),
            $more > 0 ? ' (' . $more . ' more not listed.)' : ''
        ));
    }

    /**
     * @param ContentIdentityInterface $identity
     * @return string
     */
    private function describe(ContentIdentityInterface $identity): string
    {
        return sprintf(
            '%s %s (%s)',
            (string) $identity->getEntityType(),
            (string) $identity->getEntityId(),
            (string) $identity->getField()
        );
    }
}
