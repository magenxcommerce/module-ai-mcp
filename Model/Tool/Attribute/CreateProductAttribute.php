<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Create a product attribute.
 */
class CreateProductAttribute extends AbstractTool
{
    /** Input types that carry a fixed list of options. */
    private const OPTION_INPUTS = ['select', 'multiselect'];

    /** The input types Magento offers for a product attribute. */
    private const INPUT_TYPES = [
        'text',
        'textarea',
        'texteditor',
        'date',
        'datetime',
        'boolean',
        'multiselect',
        'select',
        'price',
        'media_image',
        'weee',
    ];

    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param ProductAttributeInterfaceFactory $attributeFactory
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeFlagArguments $flags
     * @param AttributeProjector $projector
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly AttributeOptionInterfaceFactory $optionFactory,
        private readonly AttributeFlagArguments $flags,
        private readonly AttributeProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_product_attribute';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a product attribute. This changes the catalogue\'s schema: the attribute '
            . 'appears on every product of the attribute sets it is later added to, and it has to '
            . 'be assigned to a set with assign_product_attribute_to_set before any product can '
            . 'carry a value. Choose scope carefully — "store" is what a translatable attribute '
            . 'needs and cannot be narrowed later without losing per-store values.';
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
                        'description' => 'Lowercase code, letters, digits and underscores, at most '
                            . '30 characters. Cannot be changed afterwards.',
                    ],
                    'frontend_input' => [
                        'type' => 'string',
                        'enum' => self::INPUT_TYPES,
                        'description' => 'The input type, which also decides how the value is '
                            . 'stored. Cannot be changed afterwards.',
                    ],
                    'options' => [
                        'type' => 'array',
                        'description' => 'Initial options, for a "select" or "multiselect" '
                            . 'attribute only. More can be added later with '
                            . 'add_product_attribute_option.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'sort_order' => ['type' => 'integer'],
                            ],
                            'required' => ['label'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                $this->flags->schemaProperties()
            ),
            'required' => ['attribute_code', 'frontend_input', 'default_frontend_label'],
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
        // Adds an attribute; a code already in use is refused rather than
        // overwritten.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $code = strtolower($this->requireString($arguments, 'attribute_code'));
        if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $code) !== 1) {
            throw new LocalizedException(__(
                'The attribute code "%1" is not usable. It must start with a letter and contain '
                . 'only lowercase letters, digits and underscores, up to 30 characters.',
                $code
            ));
        }

        $input = $this->requireString($arguments, 'frontend_input');
        if (!in_array($input, self::INPUT_TYPES, true)) {
            throw new LocalizedException(
                __('The "frontend_input" argument must be one of: %1.', implode(', ', self::INPUT_TYPES))
            );
        }

        $this->requireString($arguments, 'default_frontend_label');

        $options = $this->buildOptions($arguments, $input);

        $attribute = $this->attributeFactory->create();
        $attribute->setAttributeCode($code);
        $attribute->setFrontendInput($input);
        // Without this the attribute is treated as a system attribute, which
        // among other things makes it undeletable through the admin.
        $attribute->setIsUserDefined(true);
        if ($options !== []) {
            $attribute->setOptions($options);
        }

        $this->flags->applyTo($attribute, $arguments);

        $saved = $this->attributeRepository->save($attribute);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'options_created' => count($options),
            'next_step' => 'Assign it to an attribute set with assign_product_attribute_to_set '
                . 'before setting it on a product.',
            'reindex_required' => true,
        ] + $this->projector->toSummary($saved);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $input
     * @return \Magento\Eav\Api\Data\AttributeOptionInterface[]
     * @throws LocalizedException
     */
    private function buildOptions(array $arguments, string $input): array
    {
        $rows = $this->optionalArray($arguments, 'options');
        if ($rows === []) {
            return [];
        }

        if (!in_array($input, self::OPTION_INPUTS, true)) {
            throw new LocalizedException(__(
                'Options only apply to a "select" or "multiselect" attribute; this one is "%1".',
                $input
            ));
        }

        $options = [];
        $labels = [];
        foreach ($rows as $index => $row) {
            $position = (int) $index + 1;
            if (!is_array($row)) {
                throw new LocalizedException(__('Option %1 must be an object with a label.', $position));
            }

            $label = $row['label'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                throw new LocalizedException(__('Option %1 needs a "label".', $position));
            }
            $label = trim($label);

            // Magento refuses a duplicate label when options are added one at a
            // time, but not within a single create, so two identical labels
            // would both be stored.
            $key = strtolower($label);
            if (isset($labels[$key])) {
                throw new LocalizedException(__(
                    'Options %1 and %2 have the same label "%3". Give it once.',
                    $labels[$key],
                    $position,
                    $label
                ));
            }
            $labels[$key] = $position;

            $option = $this->optionFactory->create();
            $option->setLabel($label);

            $sortOrder = $row['sort_order'] ?? null;
            if ($sortOrder !== null) {
                if (!is_int($sortOrder) && !(is_string($sortOrder) && ctype_digit($sortOrder))) {
                    throw new LocalizedException(
                        __('Option %1 has an unusable "sort_order"; it must be a whole number.', $position)
                    );
                }
                $option->setSortOrder((int) $sortOrder);
            }

            $options[] = $option;
        }

        return $options;
    }
}
