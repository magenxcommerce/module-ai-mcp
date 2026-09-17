<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Configurable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;

/**
 * Add a variant to a configurable product.
 */
class AddConfigurableChild extends AbstractTool
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
        return 'add_configurable_child';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a simple product as a variant of a configurable product. The simple has to '
            . 'already carry a value for every attribute the configurable varies on, and that '
            . 'combination must not be taken by an existing variant — Magento refuses the call '
            . 'otherwise and says which. This does not create the configurable\'s attribute '
            . 'options; those are set up in the admin.';
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
                'child_sku' => [
                    'type' => 'string',
                    'description' => 'The simple product to add as a variant.',
                ],
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
    protected function isDestructive(): bool
    {
        // Associates an existing simple product; nothing is removed, and a
        // combination already taken is refused rather than replaced.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $childSku = $this->requireString($arguments, 'child_sku');

        $this->linkManagement->addChild($sku, $childSku);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'sku' => $sku,
            'child_sku' => $childSku,
            'reindex_required' => true,
        ];
    }
}
