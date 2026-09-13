<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Link;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductLinkTypeInterface;
use Magento\Catalog\Api\ProductLinkTypeListInterface;

/**
 * List the link types this installation has.
 */
class ListProductLinkTypes extends AbstractTool
{
    /**
     * @param ProductLinkTypeListInterface $linkTypeList
     */
    public function __construct(
        private readonly ProductLinkTypeListInterface $linkTypeList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_product_link_types';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the product link types this installation supports — normally related, '
            . 'upsell, crosssell and associated. The other link tools take the "name", not the '
            . 'numeric "code". Call this rather than assuming a name: a module can add its own, '
            . 'and an unknown one is refused.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
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
        $types = $this->linkTypeList->getItems();

        return [
            'total_count' => count($types),
            'items' => array_map(
                static fn (ProductLinkTypeInterface $type): array => [
                    // The name is what link_type takes elsewhere; the code is
                    // Magento's internal id for the type and is reported only
                    // so the two are not mistaken for each other.
                    'name' => $type->getName(),
                    'code' => $type->getCode() === null ? null : (int) $type->getCode(),
                ],
                array_values($types)
            ),
        ];
    }
}
