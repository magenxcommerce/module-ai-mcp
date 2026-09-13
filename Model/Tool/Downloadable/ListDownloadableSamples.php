<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\Data\SampleInterface;
use Magento\Downloadable\Api\SampleRepositoryInterface;

/**
 * Read a downloadable product's free samples.
 */
class ListDownloadableSamples extends AbstractTool
{
    /**
     * @param SampleRepositoryInterface $sampleRepository
     * @param ProductTypeLocator $productLocator
     * @param DownloadableProjector $projector
     */
    public function __construct(
        private readonly SampleRepositoryInterface $sampleRepository,
        private readonly ProductTypeLocator $productLocator,
        private readonly DownloadableProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_downloadable_samples';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a downloadable product\'s samples — the files anyone can download without '
            . 'buying. These are the product\'s own samples, separate from the per-link sample '
            . 'that save_downloadable_link sets. The file bytes are never returned.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Sku of the downloadable product.'],
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
        $this->productLocator->locate($sku, ListDownloadableLinks::TYPE_DOWNLOADABLE);

        return [
            'sku' => $sku,
            'samples' => array_map(
                fn (SampleInterface $sample): array => $this->projector->sample($sample),
                array_values($this->sampleRepository->getList($sku))
            ),
        ];
    }
}
