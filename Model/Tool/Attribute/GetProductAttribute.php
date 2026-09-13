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
 * Read one product attribute.
 */
class GetProductAttribute extends AbstractTool
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
        return 'get_product_attribute';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one product attribute by code: its input type, scope, storefront flags and '
            . 'option list. Use this to find the option ids update_product needs for a dropdown '
            . 'or multiselect attribute, since those take ids and not labels.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attribute_code' => [
                    'type' => 'string',
                    'description' => 'The attribute code, e.g. "color" or "manufacturer".',
                ],
                'include_options' => [
                    'type' => 'boolean',
                    'description' => 'Return the option list. Default false, which reports how many '
                        . 'options there are instead.',
                ],
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

        return $this->projector->toDetail(
            $attribute,
            (bool) $this->optionalBool($arguments, 'include_options', false)
        );
    }
}
