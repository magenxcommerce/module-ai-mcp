<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove an attribute from an attribute set.
 */
class UnassignProductAttributeFromSet extends AbstractTool
{
    /**
     * @param ProductAttributeManagementInterface $attributeManagement
     */
    public function __construct(
        private readonly ProductAttributeManagementInterface $attributeManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'unassign_product_attribute_from_set';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove an attribute from an attribute set. Products using that set stop showing '
            . 'the attribute and their stored values for it become unreachable, though the '
            . 'attribute itself and its values elsewhere survive — so this is the reversible way '
            . 'to stop using an attribute, unlike delete_product_attribute. Magento refuses to '
            . 'remove a system attribute this way.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attribute_set_id' => [
                    'type' => 'integer',
                    'description' => 'The set to remove from; list_attribute_sets reports the ids.',
                ],
                'attribute_code' => ['type' => 'string', 'description' => 'The attribute to remove.'],
            ],
            'required' => ['attribute_set_id', 'attribute_code'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::sets';
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
        $attributeSetId = $this->requireInt($arguments, 'attribute_set_id');
        $attributeCode = $this->requireString($arguments, 'attribute_code');

        if ($this->attributeManagement->unassign($attributeSetId, $attributeCode) !== true) {
            throw new LocalizedException(__(
                'Magento did not remove "%1" from attribute set %2. Check that the set contains it '
                . 'with list_attribute_sets.',
                $attributeCode,
                $attributeSetId
            ));
        }

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'attribute_set_id' => $attributeSetId,
            'attribute_code' => $attributeCode,
            'reindex_required' => true,
        ];
    }
}
