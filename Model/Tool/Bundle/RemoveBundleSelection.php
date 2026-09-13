<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\ProductLinkManagementInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Take one product out of a bundle option.
 */
class RemoveBundleSelection extends AbstractTool
{
    /**
     * @param ProductLinkManagementInterface $linkManagement
     * @param ProductOptionRepositoryInterface $optionRepository
     * @param ProductTypeLocator $productLocator
     * @param BundleOptionProjector $projector
     */
    public function __construct(
        private readonly ProductLinkManagementInterface $linkManagement,
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
        return 'remove_bundle_selection';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one product from a bundle option. The product itself is not deleted — it '
            . 'simply stops being offered by this bundle. Removing the last selection from a '
            . 'required option leaves the bundle unbuyable, so the result reports how many are '
            . 'left.';
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
                    'description' => 'Option the selection sits in, as list_bundle_options reports it.',
                ],
                'child_sku' => [
                    'type' => 'string',
                    'description' => 'Sku of the product to stop offering in that option.',
                ],
            ],
            'required' => ['sku', 'option_id', 'child_sku'],
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
        $childSku = $this->requireString($arguments, 'child_sku');
        $this->productLocator->locate($sku, ListBundleOptions::TYPE_BUNDLE);

        // Magento reports a selection that was never there as a generic "could
        // not delete", which does not say whether the option or the child sku
        // was the wrong one.
        $option = $this->optionRepository->get($sku, $optionId);
        $links = array_values($option->getProductLinks() ?? []);
        $present = array_values(array_filter(
            $links,
            static fn (LinkInterface $link): bool => $link->getSku() === $childSku
        ));

        if ($present === []) {
            throw new LocalizedException(__(
                'Option %1 of bundle "%2" does not offer "%3". It offers: %4.',
                $optionId,
                $sku,
                $childSku,
                $links === []
                    ? '(nothing)'
                    : implode(', ', array_map(static fn (LinkInterface $l): string => (string) $l->getSku(), $links))
            ));
        }

        $this->linkManagement->removeChild($sku, $optionId, $childSku);

        return [
            'removed' => true,
            'sku' => $sku,
            'option_id' => $optionId,
            'child_sku' => $childSku,
            'option' => $this->projector->toArray($this->optionRepository->get($sku, $optionId)),
        ];
    }
}
