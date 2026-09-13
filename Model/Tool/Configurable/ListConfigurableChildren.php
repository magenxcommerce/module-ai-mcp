<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Configurable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductProjector;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read the variants of a configurable product.
 */
class ListConfigurableChildren extends AbstractTool
{
    /**
     * @param LinkManagementInterface $linkManagement
     * @param ProductProjector $projector
     */
    public function __construct(
        private readonly LinkManagementInterface $linkManagement,
        private readonly ProductProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_configurable_children';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the simple products that are the variants of a configurable product. Returns '
            . 'a summary per variant. A configurable with no children is not buyable, which is '
            . 'the usual reason one does not appear on the storefront.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The configurable product\'s sku.'],
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
            $children = $this->linkManagement->getChildren($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        return [
            'sku' => $sku,
            'total_count' => count($children),
            'items' => array_map(
                fn ($child): array => $this->projector->toSummary($child),
                array_values($children)
            ),
        ];
    }
}
