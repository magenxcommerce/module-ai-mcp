<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Bundle\Api\Data\OptionInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;

/**
 * Read a bundle's options and the selections inside them.
 */
class ListBundleOptions extends AbstractTool
{
    /** Magento's type id for a bundle product. */
    public const TYPE_BUNDLE = 'bundle';

    /**
     * @param ProductOptionRepositoryInterface $optionRepository
     * @param ProductTypeLocator $productLocator
     * @param BundleOptionProjector $projector
     */
    public function __construct(
        private readonly ProductOptionRepositoryInterface $optionRepository,
        private readonly ProductTypeLocator $productLocator,
        private readonly BundleOptionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_bundle_options';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the options of a bundle product — each option\'s title, input type and whether '
            . 'it is required, with the selections inside it and the option_id the other bundle '
            . 'tools take. A bundle with no options cannot be bought.';
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
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $this->productLocator->locate($sku, self::TYPE_BUNDLE);

        $options = array_values($this->optionRepository->getList($sku));

        return [
            'sku' => $sku,
            'options' => array_map(
                fn (OptionInterface $option): array => $this->projector->toArray($option),
                $options
            ),
        ];
    }
}
