<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Link;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\ProductLinkManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read a product's links of one type.
 */
class GetProductLinks extends AbstractTool
{
    /**
     * @param ProductLinkManagementInterface $linkManagement
     */
    public function __construct(
        private readonly ProductLinkManagementInterface $linkManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_product_links';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a product\'s linked products of one type: related, upsell, crosssell or '
            . 'associated. Read this before set_product_links, which replaces the whole list for '
            . 'the type rather than adding to it.';
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
                'link_type' => [
                    'type' => 'string',
                    'description' => 'Link type name, e.g. "related", "upsell", "crosssell" or '
                        . '"associated" — the "name" from list_product_link_types, not its '
                        . 'numeric code.',
                ],
            ],
            'required' => ['sku', 'link_type'],
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
        $linkType = $this->requireString($arguments, 'link_type');

        try {
            $links = $this->linkManagement->getLinkedItemsByType($sku, $linkType);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        return [
            'sku' => $sku,
            'link_type' => $linkType,
            'total_count' => count($links),
            'items' => array_map(
                static fn (ProductLinkInterface $link): array => [
                    'linked_product_sku' => $link->getLinkedProductSku(),
                    'linked_product_type' => $link->getLinkedProductType(),
                    'position' => $link->getPosition() === null ? null : (int) $link->getPosition(),
                ],
                array_values($links)
            ),
        ];
    }
}
