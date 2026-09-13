<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Api\Data\SampleInterface;

/**
 * Shrinks a downloadable link or sample to something worth sending to a model.
 *
 * Never the file bytes: these are paid-for files, and a read tool that handed
 * them back would turn listing a product's links into downloading them.
 */
class DownloadableProjector
{
    private const SHAREABLE_LABELS = [0 => 'no', 1 => 'yes', 2 => 'use_config'];

    /**
     * @param LinkInterface $link
     * @return array<string, mixed>
     */
    public function link(LinkInterface $link): array
    {
        $shareable = $link->getIsShareable() === null ? null : (int) $link->getIsShareable();

        return [
            'link_id' => (int) $link->getId(),
            'title' => $link->getTitle(),
            'sort_order' => (int) $link->getSortOrder(),
            'price' => $link->getPrice() === null ? null : (float) $link->getPrice(),
            // 0 means unlimited, which is Magento's default for a new link.
            'number_of_downloads' => (int) $link->getNumberOfDownloads(),
            'is_shareable' => $shareable,
            'is_shareable_label' => $shareable === null ? null : (self::SHAREABLE_LABELS[$shareable] ?? null),
            'link_type' => $link->getLinkType(),
            'link_url' => $link->getLinkUrl(),
            'link_file' => $link->getLinkFile(),
            'sample_type' => $link->getSampleType(),
            'sample_url' => $link->getSampleUrl(),
            'sample_file' => $link->getSampleFile(),
        ];
    }

    /**
     * @param SampleInterface $sample
     * @return array<string, mixed>
     */
    public function sample(SampleInterface $sample): array
    {
        return [
            'sample_id' => (int) $sample->getId(),
            'title' => $sample->getTitle(),
            'sort_order' => (int) $sample->getSortOrder(),
            'sample_type' => $sample->getSampleType(),
            'sample_url' => $sample->getSampleUrl(),
            'sample_file' => $sample->getSampleFile(),
        ];
    }
}
