<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\Data\OptionInterface;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Api\ProductOptionManagementInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Create or change one option on a bundle product.
 *
 * Create-or-update on option_id, the way the RMA lookup tools work: omit it to
 * add an option, pass it to change one.
 */
class SaveBundleOption extends AbstractTool
{
    /** How the option is presented, and whether it takes one selection or several. */
    private const TYPES = ['select', 'radio', 'checkbox', 'multi'];

    private const MODE_REPLACE = 'replace';
    private const MODE_APPEND = 'append';

    /**
     * @param ProductOptionManagementInterface $optionManagement
     * @param ProductOptionRepositoryInterface $optionRepository
     * @param OptionInterfaceFactory $optionFactory
     * @param ProductTypeLocator $productLocator
     * @param BundleLinkArguments $linkArguments
     * @param BundleOptionProjector $projector
     */
    public function __construct(
        private readonly ProductOptionManagementInterface $optionManagement,
        private readonly ProductOptionRepositoryInterface $optionRepository,
        private readonly OptionInterfaceFactory $optionFactory,
        private readonly ProductTypeLocator $productLocator,
        private readonly BundleLinkArguments $linkArguments,
        private readonly BundleOptionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_bundle_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an option to a bundle product, or change one. Omit option_id to create, pass it '
            . 'to update. Supplying product_links REPLACES the option\'s selections with exactly '
            . 'that list — any selection you leave out is removed — so pass mode "append" to keep '
            . 'the existing ones and add to them. Omitting product_links entirely leaves the '
            . 'selections alone. The child products have to exist already.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Sku of the bundle product.'],
                'option_id' => [
                    'type' => 'integer',
                    'description' => 'Option to change, as list_bundle_options reports it. Omit to '
                        . 'create a new option.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'What the option is called on the product page. Required when '
                        . 'creating.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => self::TYPES,
                    'description' => 'How the option is presented: select or radio for one choice, '
                        . 'checkbox or multi for several. Required when creating.',
                ],
                'required' => [
                    'type' => 'boolean',
                    'description' => 'Whether the customer has to choose from this option.',
                ],
                'position' => ['type' => 'integer', 'description' => 'Sort position among the options.'],
                'product_links' => [
                    'type' => 'array',
                    'items' => $this->linkArguments->schema(),
                    'description' => 'The selections offered by this option. Replaces the existing '
                        . 'set unless mode is "append".',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => [self::MODE_REPLACE, self::MODE_APPEND],
                    'description' => 'What product_links does to the selections already on the '
                        . 'option. Defaults to "replace", which is Magento\'s own behaviour.',
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
        $this->productLocator->locate($sku, ListBundleOptions::TYPE_BUNDLE);

        $optionId = $this->optionalInt($arguments, 'option_id');
        $existing = $optionId === null ? null : $this->existingOption($sku, $optionId);

        $option = $this->optionFactory->create();
        $option->setSku($sku);
        if ($optionId !== null) {
            $option->setOptionId($optionId);
        }

        $option->setTitle($this->title($arguments, $existing));
        $option->setType($this->type($arguments, $existing));
        $option->setRequired($this->optionalBool($arguments, 'required', $existing === null
            ? true
            : (bool) $existing->getRequired()));
        $option->setPosition(
            $this->optionalInt($arguments, 'position') ?? (int) ($existing?->getPosition() ?? 0)
        );

        $links = $this->productLinks($arguments, $existing);
        if ($links !== null) {
            $option->setProductLinks($links);
        }

        $savedId = (int) $this->optionManagement->save($option);

        return [
            'saved' => true,
            'created' => $optionId === null,
            'sku' => $sku,
            'option' => $this->projector->toArray($this->optionRepository->get($sku, $savedId)),
        ];
    }

    /**
     * @param string $sku
     * @param int $optionId
     * @return OptionInterface
     * @throws LocalizedException
     */
    private function existingOption(string $sku, int $optionId): OptionInterface
    {
        foreach ($this->optionRepository->getList($sku) as $option) {
            if ((int) $option->getOptionId() === $optionId) {
                return $option;
            }
        }

        throw new LocalizedException(__(
            'Bundle "%1" has no option with option_id %2. list_bundle_options reports the ids it has.',
            $sku,
            $optionId
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param OptionInterface|null $existing
     * @return string
     * @throws LocalizedException
     */
    private function title(array $arguments, ?OptionInterface $existing): string
    {
        $title = $this->optionalString($arguments, 'title') ?? $existing?->getTitle();
        if ($title === null || $title === '') {
            throw new LocalizedException(__('The "title" argument is required when creating an option.'));
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param OptionInterface|null $existing
     * @return string
     * @throws LocalizedException
     */
    private function type(array $arguments, ?OptionInterface $existing): string
    {
        $type = $this->optionalString($arguments, 'type') ?? $existing?->getType();
        if ($type === null || $type === '') {
            throw new LocalizedException(__(
                'The "type" argument is required when creating an option, and must be one of: %1.',
                implode(', ', self::TYPES)
            ));
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new LocalizedException(__(
                'The "type" argument must be one of: %1.',
                implode(', ', self::TYPES)
            ));
        }

        return $type;
    }

    /**
     * The selections to save, or null to leave the existing ones untouched.
     *
     * Magento replaces the whole set when it is handed one, which is why
     * "append" has to read the current selections and carry them through — the
     * same choice set_product_links makes for the same reason.
     *
     * @param array<string, mixed> $arguments
     * @param OptionInterface|null $existing
     * @return array<int, LinkInterface>|null
     * @throws LocalizedException
     */
    private function productLinks(array $arguments, ?OptionInterface $existing): ?array
    {
        if (!array_key_exists('product_links', $arguments)) {
            return null;
        }

        $mode = $this->optionalString($arguments, 'mode', self::MODE_REPLACE);
        if ($mode !== self::MODE_REPLACE && $mode !== self::MODE_APPEND) {
            throw new LocalizedException(__(
                'The "mode" argument must be "%1" or "%2".',
                self::MODE_REPLACE,
                self::MODE_APPEND
            ));
        }

        $optionId = $existing === null ? null : (int) $existing->getOptionId();
        $links = [];
        if ($mode === self::MODE_APPEND && $existing !== null) {
            $links = array_values($existing->getProductLinks() ?? []);
        }

        foreach ($this->optionalArray($arguments, 'product_links') as $definition) {
            if (!is_array($definition)) {
                throw new LocalizedException(__('Every entry in "product_links" must be an object.'));
            }
            $links[] = $this->linkArguments->build($definition, $optionId);
        }

        if ($links === []) {
            throw new LocalizedException(__(
                'An option with no selections cannot be bought. Pass at least one entry in '
                . '"product_links", or omit the argument to leave the existing selections alone.'
            ));
        }

        return $links;
    }
}
