<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add an option to a dropdown or multiselect attribute.
 */
class AddProductAttributeOption extends AbstractTool
{
    /**
     * @param ProductAttributeOptionManagementInterface $optionManagement
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionLabelInterfaceFactory $labelFactory
     */
    public function __construct(
        private readonly ProductAttributeOptionManagementInterface $optionManagement,
        private readonly AttributeOptionInterfaceFactory $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory $labelFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_product_attribute_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an option to a product attribute that has a fixed list of values — a dropdown, '
            . 'multiselect or swatch. Returns the new option id, which is what update_product '
            . 'takes for that attribute. Magento refuses a label that already exists on the '
            . 'attribute, so this cannot create a duplicate. Labels can also be set per store view.';
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
                    'description' => 'The attribute to add the option to.',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'The option label in the default scope.',
                ],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Position in the option list.',
                ],
                'is_default' => [
                    'type' => 'boolean',
                    'description' => 'Make this the attribute\'s default value.',
                ],
                'store_labels' => [
                    'type' => 'array',
                    'description' => 'Per-store-view labels for this option.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'store_id' => [
                                'type' => 'integer',
                                'description' => 'Store view id; list_stores reports them.',
                            ],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['store_id', 'label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['attribute_code', 'label'],
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
    protected function isDestructive(): bool
    {
        // Adds an option to the list; Magento refuses a duplicate label, so
        // no existing option is replaced.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $code = $this->requireString($arguments, 'attribute_code');
        $label = $this->requireString($arguments, 'label');

        $option = $this->optionFactory->create();
        $option->setLabel($label);

        $sortOrder = $this->optionalInt($arguments, 'sort_order');
        if ($sortOrder !== null) {
            $option->setSortOrder($sortOrder);
        }

        $isDefault = $this->optionalBool($arguments, 'is_default');
        if ($isDefault !== null) {
            $option->setIsDefault($isDefault);
        }

        $storeLabels = $this->storeLabels($arguments);
        if ($storeLabels !== []) {
            $option->setStoreLabels($storeLabels);
        }

        // Returns the new option's id. Magento refuses the call outright if the
        // label already exists on this attribute, so there is no duplicate to
        // guard against here.
        $optionId = $this->optionManagement->add($code, $option);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'attribute_code' => $code,
            'option_id' => (string) $optionId,
            'label' => $label,
            'store_labels_set' => count($storeLabels),
            'reindex_required' => true,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return \Magento\Eav\Api\Data\AttributeOptionLabelInterface[]
     * @throws LocalizedException
     */
    private function storeLabels(array $arguments): array
    {
        $labels = [];
        foreach ($this->optionalArray($arguments, 'store_labels') as $index => $row) {
            $position = (int) $index + 1;
            if (!is_array($row)) {
                throw new LocalizedException(
                    __('Store label %1 must be an object with store_id and label.', $position)
                );
            }

            $storeId = $row['store_id'] ?? null;
            if (!is_int($storeId) && !(is_string($storeId) && ctype_digit($storeId))) {
                throw new LocalizedException(__('Store label %1 needs a numeric "store_id".', $position));
            }

            $text = $row['label'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                throw new LocalizedException(__('Store label %1 needs a "label".', $position));
            }

            $storeLabel = $this->labelFactory->create();
            $storeLabel->setStoreId((int) $storeId);
            $storeLabel->setLabel(trim($text));
            $labels[] = $storeLabel;
        }

        return $labels;
    }
}
