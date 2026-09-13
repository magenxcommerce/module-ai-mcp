<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\MediaGallery;

use Magenx\AiMcp\Model\MediaPathPolicy;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Media\Base64Payload;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaGalleryApi\Api\Data\AssetInterfaceFactory;
use Magento\MediaGalleryApi\Api\SaveAssetsInterface;

/**
 * Put an image into the media gallery.
 *
 * The bytes land under pub/media, which the web server hands out directly, so
 * everything about where and under what name is decided by
 * {@see MediaPathPolicy} rather than by the caller.
 */
class UploadMediaGalleryAsset extends AbstractTool
{
    /** Where Magento records an asset it did not import from anywhere else. */
    private const SOURCE_LOCAL = 'Local';

    /**
     * @param Filesystem $filesystem
     * @param SaveAssetsInterface $saveAssets
     * @param AssetInterfaceFactory $assetFactory
     * @param MediaPathPolicy $pathPolicy
     * @param Base64Payload $payload
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly SaveAssetsInterface $saveAssets,
        private readonly AssetInterfaceFactory $assetFactory,
        private readonly MediaPathPolicy $pathPolicy,
        private readonly Base64Payload $payload
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'upload_media_gallery_asset';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an image to the media gallery, for CMS content to reference. The image has to '
            . 'be supplied as base64 — there is no URL or file-path form — so in practice it comes '
            . 'from a person. At most 4 MB decoded, JPEG, PNG, GIF or WebP only, stored under the '
            . 'gallery root with an extension matching the actual bytes. It never overwrites: a '
            . 'name already in use is an error, because replacing a file changes every page '
            . 'already pointing at it. The file is public at its media URL as soon as this '
            . 'succeeds.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'base64_encoded_data' => [
                    'type' => 'string',
                    'description' => 'The image file, base64 encoded. Not a URL and not a path.',
                ],
                'mime_type' => [
                    'type' => 'string',
                    'enum' => $this->pathPolicy->mimeTypes(),
                    'description' => 'What the bytes are. Checked against the bytes themselves and '
                        . 'against the file name\'s extension.',
                ],
                'file_name' => [
                    'type' => 'string',
                    'description' => 'Name to store it under, e.g. "hero.jpg". A name only — put '
                        . 'folders in "directory".',
                ],
                'directory' => [
                    'type' => 'string',
                    'description' => 'Optional folder under the gallery root, e.g. "banners/2026". '
                        . 'Created if it does not exist.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title shown in the gallery. Defaults to the file name.',
                ],
            ],
            'required' => ['base64_encoded_data', 'mime_type', 'file_name'],
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
        $mimeType = $this->requireString($arguments, 'mime_type');
        $path = $this->pathPolicy->resolve(
            $this->requireString($arguments, 'file_name'),
            $this->optionalString($arguments, 'directory'),
            $mimeType
        );

        $decoded = $this->payload->decode(
            $this->requireString($arguments, 'base64_encoded_data'),
            'base64_encoded_data'
        );
        $info = $this->payload->imageInfo($decoded, 'base64_encoded_data', $mimeType);

        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        if ($media->isExist($path)) {
            throw new LocalizedException(__(
                'A file already exists at "%1". Uploading over it would change every page already '
                . 'pointing at that url, so pick another file_name or directory.',
                $path
            ));
        }

        $media->writeFile($path, $decoded);

        $asset = $this->assetFactory->create([
            'path' => $path,
            'title' => $this->optionalString($arguments, 'title')
                ?? $this->requireString($arguments, 'file_name'),
            'contentType' => $mimeType,
            'width' => $info['width'],
            'height' => $info['height'],
            'size' => strlen($decoded),
            'source' => self::SOURCE_LOCAL,
        ]);

        // Written first, indexed second: the file on disk is what the storefront
        // serves, and the gallery record is what the admin browses.
        $this->saveAssets->execute([$asset]);

        return [
            'created' => true,
            'path' => $path,
            'content_type' => $mimeType,
            'width' => $info['width'],
            'height' => $info['height'],
            'size' => strlen($decoded),
        ];
    }
}
