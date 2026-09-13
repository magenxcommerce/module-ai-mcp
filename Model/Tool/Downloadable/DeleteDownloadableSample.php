<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\SampleRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Remove one sample from a downloadable product.
 */
class DeleteDownloadableSample extends AbstractTool
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
        return 'delete_downloadable_sample';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one free sample from a downloadable product. The links the product sells are '
            . 'untouched; only the free preview goes.';
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
                'sample_id' => [
                    'type' => 'integer',
                    'description' => 'Sample to remove, as list_downloadable_samples reports it.',
                ],
            ],
            'required' => ['sku', 'sample_id'],
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
        $sampleId = $this->requireInt($arguments, 'sample_id');
        $this->productLocator->locate($sku, ListDownloadableLinks::TYPE_DOWNLOADABLE);

        $removed = null;
        $remaining = 0;
        foreach ($this->sampleRepository->getList($sku) as $sample) {
            if ((int) $sample->getId() === $sampleId) {
                $removed = $this->projector->sample($sample);
                continue;
            }
            $remaining++;
        }

        if ($removed === null) {
            throw new LocalizedException(__(
                'Product "%1" has no sample with sample_id %2. list_downloadable_samples reports '
                . 'the ids it has.',
                $sku,
                $sampleId
            ));
        }

        $this->sampleRepository->delete($sampleId);

        return [
            'deleted' => true,
            'sku' => $sku,
            'sample' => $removed,
            'samples_remaining' => $remaining,
        ];
    }
}
