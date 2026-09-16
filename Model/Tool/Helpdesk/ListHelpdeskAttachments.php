<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\ResourceModel\Attachment\CollectionFactory;

/**
 * What a customer attached to a ticket.
 *
 * Metadata only, and deliberately: the row is a pointer to a file under the
 * media directory, so a tool that returned its bytes by id would be a
 * file-read primitive bounded by nothing but which ids an agent can guess —
 * and attachments on a support ticket are the most personal thing in the
 * module. "Did they send the receipt, and how big is it" is what an agent
 * actually needs to decide what to do next, and the admin is one click away for
 * the file itself.
 */
class ListHelpdeskAttachments extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_helpdesk_attachments';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the files attached to one ticket, with their name, size, type and which '
            . 'message they arrived on, oldest first. File contents are never returned — this says '
            . 'what is attached, not what is in it.';
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
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::ticket';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $ticket = $this->locator->locate(
            $this->optionalString($arguments, 'code'),
            $this->optionalInt($arguments, 'ticket_id')
        );

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('ticket_id', (int) $ticket->getId());
        $collection->setOrder('attachment_id', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'attachment_id' => (int) $row->getId(),
                'message_id' => $row->getData('message_id') === null
                    ? null
                    : (int) $row->getData('message_id'),
                'file_name' => $row->getData('file_name'),
                'file_size' => (int) $row->getData('file_size'),
                'mime_type' => $row->getData('mime_type'),
                'created_at' => $row->getData('created_at'),
            ];
        }

        return [
            'ticket_id' => (int) $ticket->getId(),
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
