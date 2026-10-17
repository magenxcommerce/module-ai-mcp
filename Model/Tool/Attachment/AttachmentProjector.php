<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attachment;

use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\AttachmentType;

/**
 * Presents one `magenx_product_attachment` row.
 *
 * Never carries the bytes, for the same reason product images do not: a
 * base64 file in a tool result is noise the model cannot use. An upload is
 * identified by its `file` path below the attachments directory and its public
 * `url` — the attachments directory sits under pub/media, so that URL is
 * already reachable by anyone who has it, and the merchant needs it to check
 * the right file landed.
 */
class AttachmentProjector
{
    /**
     * @param AttachmentRepository $attachmentRepository
     * @param AttachmentPath $path
     */
    public function __construct(
        private readonly AttachmentRepository $attachmentRepository,
        private readonly AttachmentPath $path
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toArray(array $row): array
    {
        $type = (string) ($row['type'] ?? '');
        $file = $row['file'] ?? null;

        return [
            'attachment_id' => (int) $row['attachment_id'],
            'product_id' => (int) $row['product_id'],
            'type' => $type,
            'title' => (string) ($row['title'] ?? ''),
            'file' => $file,
            'external_url' => $row['external_url'] ?? null,
            'url' => $type === AttachmentType::UPLOAD && is_string($file) && $file !== ''
                ? $this->attachmentRepository->getUrl($this->path->toMediaPath($file))
                : ($row['external_url'] ?? null),
            'mime_type' => $row['mime_type'] ?? null,
            'size' => isset($row['size']) ? (int) $row['size'] : null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
