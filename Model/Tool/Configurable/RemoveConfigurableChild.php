<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Configurable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove a variant from a configurable product.
 */
class RemoveConfigurableChild extends AbstractTool
{
    /**
     * @param LinkManagementInterface $linkManagement
     */
    public function __construct(
        private readonly LinkManagementInterface $linkManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'remove_configurable_child';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove a variant from a configurable product. The simple product itself is not '
            . 'deleted — it stops being reachable through the configurable and becomes a '
            . 'standalone product, visible on its own if its visibility allows. Removing the last '
            . 'variant leaves the configurable unbuyable.';
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
                'child_sku' => ['type' => 'string', 'description' => 'The variant to remove.'],
            ],
            'required' => ['sku', 'child_sku'],
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
        $childSku = $this->requireString($arguments, 'child_sku');

        if ($this->linkManagement->removeChild($sku, $childSku) !== true) {
            // Magento answers false rather than throwing when the pairing is
            // not there to remove.
            throw new LocalizedException(__(
                'Magento did not remove "%1" from "%2". list_configurable_children shows the '
                . 'current variants.',
                $childSku,
                $sku
            ));
        }

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'child_sku' => $childSku,
            'reindex_required' => true,
        ];
    }
}
