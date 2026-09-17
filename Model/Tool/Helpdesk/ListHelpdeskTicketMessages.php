<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\Message;
use Magenx\Helpdesk\Model\ResourceModel\Message\CollectionFactory;

/**
 * Read a ticket's conversation.
 */
class ListHelpdeskTicketMessages extends AbstractTool
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
        return 'list_helpdesk_ticket_messages';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the messages on one ticket, newest first. The "type" is what matters: '
            . '"public" is correspondence the customer can see, "internal" is a staff-only note '
            . 'they cannot, and "system" is the module\'s own bookkeeping. Pass '
            . 'public_only to read only what the customer has seen — useful before replying, so a '
            . 'quote from an internal note does not end up in a customer e-mail.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'public_only' => [
                        'type' => 'boolean',
                        'description' => 'Return only messages the customer can see. Default false, '
                            . 'which includes internal notes.',
                    ],
                ],
                $this->pagingSchema()
            ),
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

        if ($this->optionalBool($arguments, 'public_only', false) === true) {
            $collection->addFieldToFilter('type', Message::TYPE_PUBLIC);
        }

        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $message) {
            /** @var Message $message */
            $items[] = [
                'message_id' => (int) $message->getId(),
                'type' => $message->getData('type'),
                // customer, staff or system.
                'author_type' => $message->getData('author_type'),
                'author_name' => $message->getData('author_name'),
                'author_email' => $message->getData('author_email'),
                'body' => $message->getData('body'),
                'created_at' => $message->getData('created_at'),
            ];
        }

        return [
            'ticket_id' => (int) $ticket->getId(),
            'code' => $ticket->getCode(),
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
