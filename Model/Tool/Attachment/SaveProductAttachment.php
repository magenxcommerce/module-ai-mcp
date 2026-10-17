<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attachment;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Media\Base64Payload;
use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\AttachmentType;
use Magenx\ProductAttachments\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Add an attachment to a product, or change one.
 *
 * The product form writes attachments through an observer on product save that
 * only acts when the form's own grid is in the posted data — which no API save,
 * including update_product, ever carries. So this writes the row the same way
 * that observer does, through the module's own repository, and stores an
 * upload exactly where the form's uploader would: `<media dir>/<sku>/<name>`.
 *
 * Replacing an upload's bytes is not an update. A row's file is what the
 * customer already downloaded and what an order email already carried; a new
 * file is a delete and an add, which the description says.
 */
class SaveProductAttachment extends AbstractTool
{
    /** Column width of `title`. */
    private const MAX_TITLE_LENGTH = 255;

    /** Column width of `external_url`, matching the module's own check. */
    private const MAX_EXTERNAL_URL_LENGTH = 1024;

    /** Name collisions tried before giving up, as Magento's uploader also does. */
    private const MAX_RENAME_ATTEMPTS = 100;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param AttachmentRepository $attachmentRepository
     * @param AttachmentPath $path
     * @param Config $config
     * @param Filesystem $filesystem
     * @param Base64Payload $payload
     * @param AttachmentProjector $projector
     * @param ProductCacheCleaner $cacheCleaner
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly Base64Payload $payload,
        private readonly AttachmentProjector $projector,
        private readonly ProductCacheCleaner $cacheCleaner
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_product_attachment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an attachment to a product, or change one. Omit attachment_id to create: type '
            . '"upload" stores file_content (base64, at most 4 MB), type "external" links '
            . 'external_url. With attachment_id, changes title, sort_order or external_url; an '
            . 'upload\'s file cannot be swapped, delete it and add again.';
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
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Attachment to change, as list_product_attachments reports it. '
                        . 'Omit to create a new one.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [AttachmentType::UPLOAD, AttachmentType::EXTERNAL],
                    'description' => 'Required when creating: "upload" for bytes stored by Magento, '
                        . '"external" for a link to somewhere else.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'What the customer sees. Defaults to the file name or the '
                        . 'link\'s host.',
                ],
                'file_content' => [
                    'type' => 'object',
                    'description' => 'The file, when type is "upload".',
                    'properties' => [
                        'base64_encoded_data' => [
                            'type' => 'string',
                            'description' => 'The file, base64 encoded. Not a URL and not a path.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'File name to store it under, e.g. "manual.pdf". A '
                                . 'name already taken for this product gets a numeric suffix.',
                        ],
                    ],
                    'required' => ['base64_encoded_data', 'name'],
                    'additionalProperties' => false,
                ],
                'external_url' => [
                    'type' => 'string',
                    'description' => 'Absolute http(s) address, when type is "external".',
                ],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Position in the list, lower first. Default 0.',
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
        // The module's own upload controller uses the same resource: whoever
        // may edit the product may attach to it.
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

        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__(
                'Product attachments are disabled. Enable them under Stores > Configuration > '
                . 'Magenx > Product Attachments first.'
            ));
        }

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }
        $productId = (int) $product->getId();
        // The product's current sku, not the argument: the folder is named
        // after what the product form would see.
        $productSku = (string) $product->getSku();

        $attachmentId = $this->optionalInt($arguments, 'attachment_id');
        if ($attachmentId !== null) {
            return $this->update($productId, $sku, $attachmentId, $arguments);
        }

        return $this->create($productId, $productSku, $sku, $arguments);
    }

    /**
     * @param int $productId
     * @param string $productSku
     * @param string $sku
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function create(int $productId, string $productSku, string $sku, array $arguments): array
    {
        $type = $this->requireString($arguments, 'type');
        if (!AttachmentType::isValid($type)) {
            throw new LocalizedException(__(
                'The "type" argument must be "%1" or "%2".',
                AttachmentType::UPLOAD,
                AttachmentType::EXTERNAL
            ));
        }

        $title = $this->title($arguments);
        $sortOrder = $this->optionalInt($arguments, 'sort_order') ?? 0;

        if ($type === AttachmentType::EXTERNAL) {
            if (isset($arguments['file_content'])) {
                throw new LocalizedException(__('An "external" attachment takes external_url, not file_content.'));
            }
            $url = $this->externalUrl($this->requireString($arguments, 'external_url'));
            $row = [
                'product_id' => $productId,
                'type' => AttachmentType::EXTERNAL,
                'file' => null,
                'external_url' => $url,
                // phpcs:ignore Magento2.Functions.DiscouragedFunction -- a merchant-supplied external URL, not a Magento one.
                'title' => $title ?? (string) (parse_url($url, PHP_URL_HOST) ?: 'External Link'),
                'mime_type' => null,
                'size' => null,
                'sort_order' => $sortOrder,
            ];
        } else {
            if (isset($arguments['external_url'])) {
                throw new LocalizedException(__('An "upload" attachment takes file_content, not external_url.'));
            }
            $file = $this->storeUpload($productSku, $this->optionalArray($arguments, 'file_content'));
            $mediaPath = $this->path->toMediaPath($file);
            $row = [
                'product_id' => $productId,
                'type' => AttachmentType::UPLOAD,
                'file' => $file,
                'external_url' => null,
                'title' => $title ?? $this->path->getFileName($file),
                'mime_type' => $this->attachmentRepository->getMimeType($mediaPath),
                'size' => $this->attachmentRepository->getFileSize($mediaPath),
                'sort_order' => $sortOrder,
            ];
        }

        $attachmentId = $this->attachmentRepository->saveAttachment(null, $row);
        $this->cacheCleaner->clean($productId);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'attachment' => $this->projector->toArray(
                $this->attachmentRepository->getAttachment($attachmentId) ?? ['attachment_id' => $attachmentId] + $row
            ),
        ];
    }

    /**
     * @param int $productId
     * @param string $sku
     * @param int $attachmentId
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function update(int $productId, string $sku, int $attachmentId, array $arguments): array
    {
        $existing = $this->attachmentRepository->getAttachment($attachmentId);
        // Another product's id is reported the same as a missing one: the
        // pairing of sku and id is what the caller asserted, and it is wrong.
        if ($existing === null || (int) $existing['product_id'] !== $productId) {
            throw new LocalizedException(
                __('Product "%1" has no attachment with attachment_id %2.', $sku, $attachmentId)
            );
        }

        if (isset($arguments['file_content'])) {
            throw new LocalizedException(__(
                'An attachment\'s file cannot be replaced. Delete it with delete_product_attachment '
                . 'and add the new file.'
            ));
        }
        if (isset($arguments['type']) && $arguments['type'] !== $existing['type']) {
            throw new LocalizedException(__(
                'An attachment\'s type cannot be changed. Delete it and add a new one.'
            ));
        }

        $changes = [];
        $title = $this->title($arguments);
        if ($title !== null) {
            $changes['title'] = $title;
        }
        $sortOrder = $this->optionalInt($arguments, 'sort_order');
        if ($sortOrder !== null) {
            $changes['sort_order'] = $sortOrder;
        }
        if (isset($arguments['external_url'])) {
            if ($existing['type'] !== AttachmentType::EXTERNAL) {
                throw new LocalizedException(__('Only an "external" attachment has an external_url.'));
            }
            $changes['external_url'] = $this->externalUrl($this->requireString($arguments, 'external_url'));
        }

        if ($changes === []) {
            throw new LocalizedException(
                __('Nothing to update: pass title, sort_order or external_url with attachment_id.')
            );
        }

        $this->attachmentRepository->saveAttachment($attachmentId, $changes);
        $this->cacheCleaner->clean($productId);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'changed_fields' => array_keys($changes),
            'attachment' => $this->projector->toArray(
                $this->attachmentRepository->getAttachment($attachmentId) ?? $changes + $existing
            ),
        ];
    }

    /**
     * Write the decoded file into the product's folder and return its path
     * below the attachments directory.
     *
     * @param string $productSku
     * @param array<mixed> $content
     * @return string
     * @throws LocalizedException
     */
    private function storeUpload(string $productSku, array $content): string
    {
        $encoded = $content['base64_encoded_data'] ?? null;
        $name = $content['name'] ?? null;
        if (!is_string($encoded) || $encoded === '' || !is_string($name) || trim($name) === '') {
            throw new LocalizedException(__(
                'An "upload" attachment needs file_content with base64_encoded_data and name.'
            ));
        }

        $name = $this->path->sanitizeSegment($name);
        $extension = $this->path->getExtension($name);
        if ($extension === '' || !$this->config->isExtensionAllowed($extension)) {
            throw new LocalizedException(__(
                'Files ending in ".%1" are not allowed. Allowed: %2.',
                $extension,
                implode(', ', $this->config->getAllowedExtensions())
            ));
        }

        $bytes = $this->payload->decode(
            $encoded,
            'file_content.base64_encoded_data',
            min(Base64Payload::MAX_BYTES, $this->config->getMaxFileSize())
        );

        $directory = $this->path->getUploadDirectory($productSku);
        $write = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $target = $this->freeName($write, $directory, $name);
        $write->writeFile($directory . '/' . $target, $bytes);

        return $this->path->sanitizeSegment($productSku) . '/' . $target;
    }

    /**
     * The name itself, or `name_1.ext`, `name_2.ext`… — never an existing file.
     *
     * Overwriting would silently change what an earlier row, and every order
     * email already sent with it, points at.
     *
     * @param WriteInterface $write
     * @param string $directory
     * @param string $name
     * @return string
     * @throws LocalizedException
     */
    private function freeName(WriteInterface $write, string $directory, string $name): string
    {
        if (!$write->isExist($directory . '/' . $name)) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $suffix = $dot === false ? '' : substr($name, $dot);
        for ($i = 1; $i <= self::MAX_RENAME_ATTEMPTS; $i++) {
            $candidate = $stem . '_' . $i . $suffix;
            if (!$write->isExist($directory . '/' . $candidate)) {
                return $candidate;
            }
        }

        throw new LocalizedException(__('Could not find a free file name for "%1".', $name));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string|null
     * @throws LocalizedException
     */
    private function title(array $arguments): ?string
    {
        $title = $this->optionalString($arguments, 'title');
        if ($title !== null && mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new LocalizedException(
                __('The "title" argument is longer than %1 characters.', self::MAX_TITLE_LENGTH)
            );
        }

        return $title;
    }

    /**
     * `http(s)` absolute URLs only, the same rule the product form applies.
     *
     * @param string $url
     * @return string
     * @throws LocalizedException
     */
    private function externalUrl(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- validating a merchant-supplied external URL's shape.
        $parts = parse_url($url);
        $valid = strlen($url) <= self::MAX_EXTERNAL_URL_LENGTH
            && is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['host'] !== ''
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);

        if (!$valid) {
            throw new LocalizedException(__(
                'The "external_url" argument must be an absolute http or https address of at most %1 characters.',
                self::MAX_EXTERNAL_URL_LENGTH
            ));
        }

        return $url;
    }
}
