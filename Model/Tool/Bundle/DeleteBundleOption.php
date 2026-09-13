<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove an option from a bundle, and every selection in it.
 */
class DeleteBundleOption extends AbstractTool
{
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
        return 'delete_bundle_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one option from a bundle product. Every selection inside it goes with it; '
            . 'the child products themselves are untouched and keep existing on their own. '
            . 'Removing the last option leaves the bundle unbuyable — the reversible alternative '
            . 'is to disable the bundle.';
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
                    'description' => 'Option to remove, as list_bundle_options reports it.',
                ],
            ],
            'required' => ['sku', 'option_id'],
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
        $optionId = $this->requireInt($arguments, 'option_id');
        $this->productLocator->locate($sku, ListBundleOptions::TYPE_BUNDLE);

        // Read before deleting so the result names what went, and so an option
        // id from a different bundle is refused rather than silently ignored.
        $removed = null;
        $remaining = 0;
        foreach ($this->optionRepository->getList($sku) as $option) {
            if ((int) $option->getOptionId() === $optionId) {
                $removed = $this->projector->toArray($option);
                continue;
            }
            $remaining++;
        }

        if ($removed === null) {
            throw new LocalizedException(__(
                'Bundle "%1" has no option with option_id %2. list_bundle_options reports the ids '
                . 'it has.',
                $sku,
                $optionId
            ));
        }

        $this->optionRepository->deleteById($sku, $optionId);

        return [
            'deleted' => true,
            'sku' => $sku,
            'option' => $removed,
            'options_remaining' => $remaining,
        ];
    }
}
