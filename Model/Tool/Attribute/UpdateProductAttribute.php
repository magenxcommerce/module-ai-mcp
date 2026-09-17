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
 * Change a product attribute's labels, scope and storefront flags.
 */
class UpdateProductAttribute extends AbstractTool
{
    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param AttributeFlagArguments $flags
     * @param AttributeProjector $projector
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeFlagArguments $flags,
        private readonly AttributeProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_product_attribute';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a product attribute\'s label, scope or storefront and grid flags. Only the '
            . 'fields you pass are changed. The attribute code and input type cannot be changed — '
            . 'Magento treats both as fixed once the attribute exists. Narrowing scope from '
            . '"store" to "global" discards the per-store values, and changing a filterable or '
            . 'searchable flag needs a reindex before the storefront agrees.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'attribute_code' => [
                        'type' => 'string',
                        'description' => 'The attribute to change. Cannot itself be changed.',
                    ],
                ],
                $this->flags->schemaProperties()
            ),
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $code = $this->requireString($arguments, 'attribute_code');

        // Loaded and mutated so a flag that was not mentioned keeps its value;
        // a rebuilt attribute would reset every storefront setting to default.
        try {
            $attribute = $this->attributeRepository->get($code);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No product attribute exists with code "%1". list_product_attributes shows the '
                . 'available codes.',
                $code
            ));
        }

        $changed = $this->flags->applyTo($attribute, $arguments);
        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides attribute_code.')
            );
        }

        $saved = $this->attributeRepository->save($attribute);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'reindex_required' => true,
        ] + $this->projector->toSummary($saved);
    }
}
