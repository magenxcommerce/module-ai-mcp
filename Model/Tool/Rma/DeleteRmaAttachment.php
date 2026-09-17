<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\AttachmentRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove a file from a return.
 *
 * The case this is for is the one nobody plans: a customer attaches something
 * to a return that should not be on the record at all — a bank statement, a
 * passport photo — and it has to come off. That makes it the rare delete worth
 * having, since the alternative is leaving personal data in place because the
 * only way to remove it is the admin.
 */
class DeleteRmaAttachment extends AbstractTool
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma_attachment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently remove one file from a return. There is no undo. '
            . 'list_rma_attachments reports the ids — and since file contents are never returned, '
            . 'check the file name and the comment it arrived on before removing it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'The attachment to remove; list_rma_attachments reports it.',
                ],
            ],
            'required' => ['attachment_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
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
        $attachmentId = $this->requireInt($arguments, 'attachment_id');

        try {
            $attachment = $this->attachmentRepository->get($attachmentId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('No return attachment exists with attachment_id %1.', $attachmentId)
            );
        }

        // Read before deleting: afterwards the audit log line is the only
        // record of which file this was.
        $fileName = $attachment->getFileName();
        $rmaId = (int) $attachment->getRmaId();

        $this->attachmentRepository->delete($attachment);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'attachment_id' => $attachmentId,
            'rma_id' => $rmaId,
            'file_name' => $fileName,
        ];
    }
}
