<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attachment;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove one attachment from a product.
 *
 * Goes through the module's repository, which deletes an upload's file along
 * with its row — the same thing removing the row in the product form does.
 * Takes the sku as well as the id so a mistyped id cannot reach another
 * product's attachment.
 */
class DeleteProductAttachment extends AbstractTool
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param AttachmentRepository $attachmentRepository
     * @param ProductCacheCleaner $cacheCleaner
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly ProductCacheCleaner $cacheCleaner
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_attachment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently remove one product attachment. An uploaded file is deleted from disk '
            . 'with it. There is no undo.';
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
                    'description' => 'The attachment to remove; list_product_attachments reports it.',
                ],
            ],
            'required' => ['sku', 'attachment_id'],
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
        $attachmentId = $this->requireInt($arguments, 'attachment_id');

        try {
            $productId = (int) $this->productRepository->get($sku)->getId();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $row = $this->attachmentRepository->getAttachment($attachmentId);
        if ($row === null || (int) $row['product_id'] !== $productId) {
            throw new LocalizedException(
                __('Product "%1" has no attachment with attachment_id %2.', $sku, $attachmentId)
            );
        }

        $this->attachmentRepository->deleteAttachment($attachmentId);
        $this->cacheCleaner->clean($productId);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'attachment_id' => $attachmentId,
            // Read before deleting: afterwards the audit log line is the only
            // record of which attachment this was.
            'type' => (string) $row['type'],
            'title' => (string) $row['title'],
            'file' => $row['file'] ?? null,
            'external_url' => $row['external_url'] ?? null,
        ];
    }
}
