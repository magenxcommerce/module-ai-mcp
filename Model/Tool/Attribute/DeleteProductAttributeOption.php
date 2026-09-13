<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeOptionManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove an option from a dropdown or multiselect attribute.
 */
class DeleteProductAttributeOption extends AbstractTool
{
    /**
     * @param ProductAttributeOptionManagementInterface $optionManagement
     */
    public function __construct(
        private readonly ProductAttributeOptionManagementInterface $optionManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_attribute_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete one option from a product attribute. This cannot be undone, and every '
            . 'product currently set to that option loses the value — a configurable product built '
            . 'on it loses the matching variant from its list. Read get_product_attribute with '
            . 'include_options for the option ids, and check what uses the option first.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attribute_code' => ['type' => 'string', 'description' => 'The attribute the option belongs to.'],
                'option_id' => [
                    'type' => 'string',
                    'description' => 'The option id, as get_product_attribute reports it under '
                        . '"value". Not the label.',
                ],
            ],
            'required' => ['attribute_code', 'option_id'],
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

        // Accepted as a string because that is what the option list reports and
        // what Magento's contract takes, but it still has to be a number: an
        // option label passed here would otherwise reach Magento as an id.
        $optionId = $arguments['option_id'] ?? null;
        if (!is_int($optionId) && !(is_string($optionId) && ctype_digit(trim($optionId)))) {
            throw new LocalizedException(__(
                'The "option_id" argument must be an option id, which get_product_attribute '
                . 'reports as "value" — not the option label.'
            ));
        }
        $optionId = trim((string) $optionId);

        if ($this->optionManagement->delete($code, $optionId) !== true) {
            // Magento answers false rather than throwing when the option is not
            // there to delete.
            throw new LocalizedException(__(
                'Magento did not delete option %1 of attribute "%2". Check the option id with '
                . 'get_product_attribute and include_options.',
                $optionId,
                $code
            ));
        }

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'attribute_code' => $code,
            'option_id' => $optionId,
            'reindex_required' => true,
        ];
    }
}
