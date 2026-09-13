<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog\Option;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove one custom option from a product.
 */
class DeleteProductOption extends AbstractTool
{
    /**
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     * @param ProductRepositoryInterface $productRepository
     * @param CustomOptionProjector $projector
     */
    public function __construct(
        private readonly ProductCustomOptionRepositoryInterface $optionRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomOptionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_product_option';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one custom option from a product, with every choice inside it. Orders '
            . 'already placed keep their own copy of what the customer chose. A required option is '
            . 'the only thing standing between a customer and buying without it, so removing one '
            . 'changes what the product means rather than just how it looks.';
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
                    'description' => 'Option to remove, as list_product_options reports it.',
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

        try {
            $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No product exists with sku "%1".', $sku));
        }

        // Read before deleting, so the result names what went and an option id
        // from another product is refused rather than acted on.
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
                'Product "%1" has no custom option with option_id %2. list_product_options reports '
                . 'the ids it has.',
                $sku,
                $optionId
            ));
        }

        $this->optionRepository->deleteByIdentifier($sku, $optionId);

        return [
            'deleted' => true,
            'sku' => $sku,
            'option' => $removed,
            'options_remaining' => $remaining,
        ];
    }
}
