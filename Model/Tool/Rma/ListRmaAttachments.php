<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\AttachmentRepositoryInterface;
use Magenx\Rma\Api\Data\AttachmentInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * The photos a customer sent with a return.
 *
 * Metadata only, for the reason
 * {@see \Magenx\AiMcp\Model\Tool\Helpdesk\ListHelpdeskAttachments} gives: the
 * row points at a file under the media directory, and a tool that returned its
 * bytes by id would be a file read bounded only by which ids an agent can
 * guess. Whether a photo of the damage exists, and how big it is, is what
 * decides the next step.
 */
class ListRmaAttachments extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param AttachmentRepositoryInterface $attachmentRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly AttachmentRepositoryInterface $attachmentRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_attachments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the files attached to one return, with their name, size, type and which '
            . 'comment they arrived on. File contents are never returned — this says what is '
            . 'attached, not what is in it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->locator->schemaProperties(), $this->pagingSchema()),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $this->searchCriteriaBuilder->addFilter('rma_id', (int) $rma->getEntityId());
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $result = $this->attachmentRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'rma_id' => (int) $rma->getEntityId(),
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (AttachmentInterface $row): array => [
                    'attachment_id' => (int) $row->getEntityId(),
                    'comment_id' => $row->getCommentId() === null ? null : (int) $row->getCommentId(),
                    'file_name' => $row->getFileName(),
                    'file_size' => (int) $row->getFileSize(),
                    'mime_type' => $row->getMimeType(),
                    'created_at' => $row->getCreatedAt(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
