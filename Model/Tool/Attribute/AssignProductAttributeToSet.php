<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeManagementInterface;

/**
 * Add an attribute to an attribute set.
 */
class AssignProductAttributeToSet extends AbstractTool
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
        return 'assign_product_attribute_to_set';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an attribute to an attribute set, which is what makes it editable on the '
            . 'products using that set. Call list_attribute_sets with an attribute_set_id first '
            . 'for the group ids — an attribute belongs to a group within the set, which is the '
            . 'section it appears under in the product form.';
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
                    'description' => 'The set to add to; list_attribute_sets reports the ids.',
                ],
                'attribute_group_id' => [
                    'type' => 'integer',
                    'description' => 'The group within that set, as list_attribute_sets reports for '
                        . 'the set. Must belong to the set given.',
                ],
                'attribute_code' => ['type' => 'string', 'description' => 'The attribute to add.'],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Position within the group. Defaults to 0.',
                ],
            ],
            'required' => ['attribute_set_id', 'attribute_group_id', 'attribute_code'],
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
        $attributeGroupId = $this->requireInt($arguments, 'attribute_group_id');
        $attributeCode = $this->requireString($arguments, 'attribute_code');
        $sortOrder = $this->optionalInt($arguments, 'sort_order') ?? 0;

        // Magento validates that the group belongs to the set and that the
        // attribute exists, reporting either as an input error.
        $attributeId = $this->attributeManagement->assign(
            $attributeSetId,
            $attributeGroupId,
            $attributeCode,
            $sortOrder
        );

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'attribute_set_id' => $attributeSetId,
            'attribute_group_id' => $attributeGroupId,
            'attribute_code' => $attributeCode,
            'attribute_id' => (int) $attributeId,
            'sort_order' => $sortOrder,
            'reindex_required' => true,
        ];
    }
}
