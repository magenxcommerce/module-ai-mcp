<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Link;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductLinkRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove one link between two products.
 */
class DeleteProductLink extends AbstractTool
{
    /**
     * @param ProductLinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly ProductLinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_link';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one linked product from one link type. Only that link goes: the products '
            . 'themselves and every other link are untouched. This is the safe way to drop a '
            . 'single link, since set_product_links replaces the whole list for the type.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The product the link belongs to.'],
                'link_type' => [
                    'type' => 'string',
                    'description' => 'Link type name, e.g. "related" — the "name" from '
                        . 'list_product_link_types.',
                ],
                'linked_product_sku' => [
                    'type' => 'string',
                    'description' => 'The linked product to remove.',
                ],
            ],
            'required' => ['sku', 'link_type', 'linked_product_sku'],
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
        $linkType = $this->requireString($arguments, 'link_type');
        $linkedSku = $this->requireString($arguments, 'linked_product_sku');

        try {
            $this->linkRepository->deleteById($sku, $linkType, $linkedSku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'Sku "%1" has no "%2" link to "%3". get_product_links shows what it has.',
                $sku,
                $linkType,
                $linkedSku
            ));
        }

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'link_type' => $linkType,
            'linked_product_sku' => $linkedSku,
        ];
    }
}
