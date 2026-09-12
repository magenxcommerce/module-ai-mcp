<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Delete a product attribute.
 *
 * Refuses a system attribute even though Magento's own contract would let one
 * through. Deleting `name`, `price`, `sku` or `status` does not fail loudly — it
 * removes the column those values live in, taking every product's value with
 * it, and there is no operation that puts them back. The admin will not offer
 * the button for a system attribute, and neither does this.
 */
class DeleteProductAttribute extends AbstractTool
{
    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param AttributeProjector $projector
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_attribute';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a product attribute and every value any product holds for it. '
            . 'This cannot be undone: there is no way to restore the values afterwards, and a '
            . 'configurable product built on the attribute loses its variants. Only attributes an '
            . 'administrator created can be deleted — Magento\'s own system attributes are '
            . 'refused. To stop using an attribute reversibly, unassign it from its attribute sets '
            . 'instead.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attribute_code' => ['type' => 'string', 'description' => 'The attribute to delete.'],
            ],
            'required' => ['attribute_code'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::attributes_attributes';
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
        $code = $this->requireString($arguments, 'attribute_code');

        try {
            $attribute = $this->attributeRepository->get($code);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No product attribute exists with code "%1". list_product_attributes shows the '
                . 'available codes.',
                $code
            ));
        }

        if (!$attribute->getIsUserDefined()) {
            throw new LocalizedException(__(
                'The attribute "%1" is one of Magento\'s system attributes and will not be deleted '
                . 'by this tool. Deleting it would remove that value from every product with no '
                . 'way to restore it. Unassign it from an attribute set instead if it should stop '
                . 'being used.',
                $code
            ));
        }

        // Captured before the delete so the result names what is now gone.
        $deleted = $this->projector->toSummary($attribute);

        $this->attributeRepository->delete($attribute);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'reindex_required' => true,
        ] + $deleted;
    }
}
