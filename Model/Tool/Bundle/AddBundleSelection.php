<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Bundle\Api\ProductLinkManagementInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add one product to an existing bundle option.
 *
 * The additive counterpart to save_bundle_option, which replaces the whole set.
 */
class AddBundleSelection extends AbstractTool
{
    /**
     * @param ProductLinkManagementInterface $linkManagement
     * @param ProductOptionRepositoryInterface $optionRepository
     * @param ProductTypeLocator $productLocator
     * @param BundleLinkArguments $linkArguments
     * @param BundleOptionProjector $projector
     */
    public function __construct(
        private readonly ProductLinkManagementInterface $linkManagement,
        private readonly ProductOptionRepositoryInterface $optionRepository,
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
        return 'add_bundle_selection';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add one product as a selection inside an existing bundle option, leaving the other '
            . 'selections alone. save_bundle_option replaces the whole list; this adds to it. The '
            . 'product has to exist already.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        // The selection's own "sku" would collide with the bundle's, so it is
        // child_sku here and mapped back before the link is built.
        $selection = $this->linkArguments->schema()['properties'];
        $childSku = $selection['sku'];
        unset($selection['sku']);

        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'sku' => ['type' => 'string', 'description' => 'Sku of the bundle product.'],
                    'option_id' => [
                        'type' => 'integer',
                        'description' => 'Option to add to, as list_bundle_options reports it.',
                    ],
                    'child_sku' => $childSku,
                ],
                $selection
            ),
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
    protected function isDestructive(): bool
    {
        // Adds one selection to an option and leaves the others alone;
        // save_bundle_option is the tool that replaces the list.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $sku = $this->requireString($arguments, 'sku');
        $optionId = $this->requireInt($arguments, 'option_id');
        $product = $this->productLocator->locate($sku, ListBundleOptions::TYPE_BUNDLE);
        $this->assertOptionExists($sku, $optionId);

        $link = $this->linkArguments->build(
            ['sku' => $this->requireString($arguments, 'child_sku')] + $arguments,
            $optionId
        );
        $linkId = $this->linkManagement->addChild($product, $optionId, $link);

        return [
            'added' => true,
            'sku' => $sku,
            'option_id' => $optionId,
            'selection_id' => (int) $linkId,
            'option' => $this->projector->toArray($this->optionRepository->get($sku, $optionId)),
        ];
    }

    /**
     * @param string $sku
     * @param int $optionId
     * @return void
     * @throws LocalizedException
     */
    private function assertOptionExists(string $sku, int $optionId): void
    {
        foreach ($this->optionRepository->getList($sku) as $option) {
            if ((int) $option->getOptionId() === $optionId) {
                return;
            }
        }

        throw new LocalizedException(__(
            'Bundle "%1" has no option with option_id %2. list_bundle_options reports the ids it has.',
            $sku,
            $optionId
        ));
    }
}
