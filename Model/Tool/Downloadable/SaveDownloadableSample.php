<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\Catalog\ProductTypeLocator;
use Magento\Downloadable\Api\Data\SampleInterface;
use Magento\Downloadable\Api\Data\SampleInterfaceFactory;
use Magento\Downloadable\Api\SampleRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add a free sample to a downloadable product, or change one.
 */
class SaveDownloadableSample extends AbstractTool
{
    /**
     * @param SampleRepositoryInterface $sampleRepository
     * @param SampleInterfaceFactory $sampleFactory
     * @param ProductTypeLocator $productLocator
     * @param DownloadableFileArguments $fileArguments
     * @param DownloadableProjector $projector
     */
    public function __construct(
        private readonly SampleRepositoryInterface $sampleRepository,
        private readonly SampleInterfaceFactory $sampleFactory,
        private readonly ProductTypeLocator $productLocator,
        private readonly DownloadableFileArguments $fileArguments,
        private readonly DownloadableProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_downloadable_sample';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a free sample to a downloadable product, or change one. Omit sample_id to '
            . 'create, pass it to update. **A sample is downloadable by anyone, without buying '
            . 'anything** — never upload the file being sold here. At most 4 MB base64-decoded.';
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
                    'sku' => ['type' => 'string', 'description' => 'Sku of the downloadable product.'],
                    'sample_id' => [
                        'type' => 'integer',
                        'description' => 'Sample to change, as list_downloadable_samples reports it. '
                            . 'Omit to create a new one.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'What the customer sees. Required when creating.',
                    ],
                    'sort_order' => ['type' => 'integer', 'description' => 'Position in the sample list.'],
                ],
                $this->fileArguments->schema('sample', 'the free sample')
            ),
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
        $this->productLocator->locate($sku, ListDownloadableLinks::TYPE_DOWNLOADABLE);

        $sampleId = $this->optionalInt($arguments, 'sample_id');
        $existing = $sampleId === null ? null : $this->existingSample($sku, $sampleId);
        $isNew = $existing === null;

        $sample = $this->sampleFactory->create();
        if ($sampleId !== null) {
            $sample->setId($sampleId);
        }

        $title = $this->optionalString($arguments, 'title') ?? $existing?->getTitle();
        if ($title === null || $title === '') {
            throw new LocalizedException(__('The "title" argument is required when creating a sample.'));
        }
        $sample->setTitle($title);
        $sample->setSortOrder(
            $this->optionalInt($arguments, 'sort_order') ?? (int) ($existing?->getSortOrder() ?? 0)
        );

        $type = $this->fileArguments->resolveType($arguments, 'sample', $existing?->getSampleType())
            ?? DownloadableFileArguments::TYPE_URL;
        $sample->setSampleType($type);
        $sample->setSampleUrl($this->fileArguments->url($arguments, 'sample', $type, $isNew)
            ?? ($type === DownloadableFileArguments::TYPE_URL ? $existing?->getSampleUrl() : null));
        $content = $this->fileArguments->content($arguments, 'sample', $type, $isNew);
        if ($content !== null) {
            $sample->setSampleFileContent($content);
        }

        $savedId = (int) $this->sampleRepository->save($sku, $sample);

        return [
            'saved' => true,
            'created' => $isNew,
            'sku' => $sku,
            'sample' => $this->projector->sample($this->existingSample($sku, $savedId)),
        ];
    }

    /**
     * @param string $sku
     * @param int $sampleId
     * @return SampleInterface
     * @throws LocalizedException
     */
    private function existingSample(string $sku, int $sampleId): SampleInterface
    {
        foreach ($this->sampleRepository->getList($sku) as $sample) {
            if ((int) $sample->getId() === $sampleId) {
                return $sample;
            }
        }

        throw new LocalizedException(__(
            'Product "%1" has no sample with sample_id %2. list_downloadable_samples reports the '
            . 'ids it has.',
            $sku,
            $sampleId
        ));
    }
}
