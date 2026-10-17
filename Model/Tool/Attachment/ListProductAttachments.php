<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attachment;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read a product's attachments (Magenx_ProductAttachments).
 *
 * Reports the module's two switches beside the rows, because both decide
 * whether a row that exists is one anybody sees: `enabled` gates the product
 * form and the sales emails, `show_on_storefront` the product page.
 */
class ListProductAttachments extends AbstractTool
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param AttachmentRepository $attachmentRepository
     * @param Config $config
     * @param AttachmentProjector $projector
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly Config $config,
        private readonly AttachmentProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_product_attachments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List a product\'s attachments (uploaded files and external links): ids, titles, '
            . 'types, order and URL, never the bytes. Also reports whether attachments are enabled '
            . 'and shown on the product page.';
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
            $productId = (int) $this->productRepository->get($sku)->getId();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $rows = $this->attachmentRepository->getProductAttachments($productId);

        return [
            'sku' => $sku,
            'product_id' => $productId,
            'enabled' => $this->config->isEnabled(),
            'show_on_storefront' => $this->config->isShowOnStorefront(),
            'total_count' => count($rows),
            'items' => array_map(
                fn (array $row): array => $this->projector->toArray($row),
                array_values($rows)
            ),
        ];
    }
}
