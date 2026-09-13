<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog\Option;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterfaceFactory;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Add a custom option to a product, or change one.
 *
 * Create-or-update on option_id, the convention the RMA lookup tools already
 * use: omit it to create, pass it to change.
 */
class SaveProductOption extends AbstractTool
{
    /** The types that offer the customer a list, and so need values. */
    private const SELECT_TYPES = ['drop_down', 'radio', 'checkbox', 'multiple'];

    /** Everything else Magento accepts. */
    private const SIMPLE_TYPES = ['field', 'area', 'file', 'date', 'date_time', 'time'];

    private const PRICE_TYPES = ['fixed', 'percent'];

    /**
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     * @param ProductRepositoryInterface $productRepository
     * @param ProductCustomOptionInterfaceFactory $optionFactory
     * @param ProductCustomOptionValuesInterfaceFactory $valueFactory
     * @param CustomOptionProjector $projector
     */
    public function __construct(
        private readonly ProductCustomOptionRepositoryInterface $optionRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCustomOptionInterfaceFactory $optionFactory,
        private readonly ProductCustomOptionValuesInterfaceFactory $valueFactory,
        private readonly CustomOptionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_product_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a custom option to one product, or change one. Omit option_id to create, pass '
            . 'it to update. The select types (drop_down, radio, checkbox, multiple) need values, '
            . 'and supplying values REPLACES the whole list — any choice left out is removed, and '
            . 'a customer who picked it keeps it only on orders already placed. The other types '
            . 'take price and sku directly instead. Neither the type nor the option_id can be '
            . 'changed to something else afterwards.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Product sku.'],
                'option_id' => [
                    'type' => 'integer',
                    'description' => 'Option to change, as list_product_options reports it. Omit to '
                        . 'create a new one.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'What the customer sees. Required when creating.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => array_merge(self::SELECT_TYPES, self::SIMPLE_TYPES),
                    'description' => 'Input type. Required when creating and fixed thereafter; '
                        . 'drop_down, radio, checkbox and multiple need values.',
                ],
                'is_require' => [
                    'type' => 'boolean',
                    'description' => 'Whether the customer must fill it in. Defaults to false.',
                ],
                'sort_order' => ['type' => 'integer', 'description' => 'Position among the options.'],
                'price' => [
                    'type' => 'number',
                    'description' => 'Surcharge for choosing it. Not used by the select types, '
                        . 'which price each value instead.',
                ],
                'price_type' => [
                    'type' => 'string',
                    'enum' => self::PRICE_TYPES,
                    'description' => '"fixed" for an amount, "percent" for a share of the product '
                        . 'price.',
                ],
                'option_sku' => [
                    'type' => 'string',
                    'description' => 'Optional sku suffix recorded on the order line when this '
                        . 'option is chosen. Named option_sku because "sku" here is the product\'s.',
                ],
                'values' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'What the choice is called.'],
                            'price' => ['type' => 'number', 'description' => 'Surcharge for this choice.'],
                            'price_type' => [
                                'type' => 'string',
                                'enum' => self::PRICE_TYPES,
                                'description' => '"fixed" or "percent".',
                            ],
                            'sort_order' => ['type' => 'integer', 'description' => 'Position in the list.'],
                            'sku' => [
                                'type' => 'string',
                                'description' => 'Optional sku suffix for this choice.',
                            ],
                        ],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'The choices offered, for a select type. Replaces the existing '
                        . 'list entirely.',
                ],
            ],
            'required' => ['sku'],
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
        try {
            $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        $optionId = $this->optionalInt($arguments, 'option_id');
        $existing = $optionId === null ? null : $this->existingOption($sku, $optionId);

        $type = $this->type($arguments, $existing);
        $option = $this->optionFactory->create();
        $option->setProductSku($sku);
        if ($optionId !== null) {
            $option->setOptionId($optionId);
        }

        $title = $this->optionalString($arguments, 'title') ?? $existing?->getTitle();
        if ($title === null || $title === '') {
            throw new LocalizedException(__('The "title" argument is required when creating an option.'));
        }
        $option->setTitle($title);
        $option->setType($type);
        $option->setIsRequire(
            $this->optionalBool($arguments, 'is_require', (bool) ($existing?->getIsRequire() ?? false))
        );
        $option->setSortOrder(
            $this->optionalInt($arguments, 'sort_order') ?? (int) ($existing?->getSortOrder() ?? 0)
        );

        if (in_array($type, self::SELECT_TYPES, true)) {
            $option->setValues($this->values($arguments, $existing, $type));
        } else {
            $price = $this->price($arguments, 'price');
            $option->setPrice($price ?? ($existing?->getPrice() === null ? null : (float) $existing->getPrice()));
            $option->setPriceType(
                $this->priceType($arguments, 'price_type') ?? $existing?->getPriceType()
            );
            $option->setSku($this->optionalString($arguments, 'option_sku') ?? $existing?->getSku());
        }

        $saved = $this->optionRepository->save($option);

        return [
            'saved' => true,
            'created' => $optionId === null,
            'sku' => $sku,
            'option' => $this->projector->toArray($saved),
        ];
    }

    /**
     * @param string $sku
     * @param int $optionId
     * @return ProductCustomOptionInterface
     * @throws LocalizedException
     */
    private function existingOption(string $sku, int $optionId): ProductCustomOptionInterface
    {
        foreach ($this->optionRepository->getList($sku) as $option) {
            if ((int) $option->getOptionId() === $optionId) {
                return $option;
            }
        }

        throw new LocalizedException(__(
            'Product "%1" has no custom option with option_id %2. list_product_options reports the '
            . 'ids it has.',
            $sku,
            $optionId
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param ProductCustomOptionInterface|null $existing
     * @return string
     * @throws LocalizedException
     */
    private function type(array $arguments, ?ProductCustomOptionInterface $existing): string
    {
        $known = array_merge(self::SELECT_TYPES, self::SIMPLE_TYPES);
        $type = $this->optionalString($arguments, 'type') ?? $existing?->getType();

        if ($type === null || $type === '') {
            throw new LocalizedException(__(
                'The "type" argument is required when creating an option, and must be one of: %1.',
                implode(', ', $known)
            ));
        }

        if (!in_array($type, $known, true)) {
            throw new LocalizedException(__(
                'The "type" argument must be one of: %1.',
                implode(', ', $known)
            ));
        }

        // Magento would accept the change and leave the stored values orphaned
        // behind an input that no longer reads them.
        if ($existing !== null && $existing->getType() !== $type) {
            throw new LocalizedException(__(
                'A custom option\'s type cannot be changed — option %1 is "%2". Delete it and '
                . 'create the option you want instead.',
                $existing->getOptionId(),
                $existing->getType()
            ));
        }

        return $type;
    }

    /**
     * The choices for a select type. Supplying them replaces the whole list.
     *
     * @param array<string, mixed> $arguments
     * @param ProductCustomOptionInterface|null $existing
     * @param string $type
     * @return array<int, ProductCustomOptionValuesInterface>
     * @throws LocalizedException
     */
    private function values(
        array $arguments,
        ?ProductCustomOptionInterface $existing,
        string $type
    ): array {
        if (!array_key_exists('values', $arguments)) {
            $current = $existing?->getValues();
            if (is_array($current) && $current !== []) {
                return array_values($current);
            }

            throw new LocalizedException(__(
                'A "%1" option needs "values" — the choices offered to the customer.',
                $type
            ));
        }

        $values = [];
        foreach ($this->optionalArray($arguments, 'values') as $definition) {
            if (!is_array($definition)) {
                throw new LocalizedException(__('Every entry in "values" must be an object.'));
            }

            $title = $definition['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                throw new LocalizedException(__('Every entry in "values" needs a "title".'));
            }

            $value = $this->valueFactory->create();
            $value->setTitle(trim($title));
            $value->setSortOrder($this->optionalInt($definition, 'sort_order') ?? 0);
            $value->setPrice($this->price($definition, 'price') ?? 0.0);
            $value->setPriceType($this->priceType($definition, 'price_type') ?? 'fixed');
            if (isset($definition['sku']) && is_string($definition['sku'])) {
                $value->setSku($definition['sku']);
            }

            $values[] = $value;
        }

        if ($values === []) {
            throw new LocalizedException(__(
                'A "%1" option needs at least one entry in "values"; an empty list would leave the '
                . 'customer nothing to choose.',
                $type
            ));
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return float|null
     * @throws LocalizedException
     */
    private function price(array $arguments, string $key): ?float
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return null;
        }

        $value = $arguments[$key];
        if (!is_int($value) && !is_float($value)) {
            throw new LocalizedException(__('The "%1" argument must be a number.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return string|null
     * @throws LocalizedException
     */
    private function priceType(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, self::PRICE_TYPES, true)) {
            throw new LocalizedException(__(
                'The "%1" argument must be one of: %2.',
                $key,
                implode(', ', self::PRICE_TYPES)
            ));
        }

        return $value;
    }
}
