<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface as Entry;

/**
 * Presents one media gallery entry.
 *
 * Never carries the image bytes. `content` is write-only in practice: Magento
 * returns it as null on a read, and a base64 image in a tool result would be
 * megabytes of noise the model cannot do anything with. The `file` path is what
 * identifies the image on disk.
 */
class MediaEntryProjector
{
    /**
     * @param Entry $entry
     * @return array<string, mixed>
     */
    public function toArray(Entry $entry): array
    {
        return [
            'id' => $entry->getId() === null ? null : (int) $entry->getId(),
            'file' => $entry->getFile(),
            'label' => $entry->getLabel(),
            'position' => $entry->getPosition() === null ? null : (int) $entry->getPosition(),
            'disabled' => (bool) $entry->isDisabled(),
            'media_type' => $entry->getMediaType(),
            // Which roles this image fills: image, small_image, thumbnail,
            // swatch_image. A role belongs to exactly one image at a time.
            'types' => array_values($entry->getTypes() ?? []),
        ];
    }
}
