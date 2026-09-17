<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\ResourceModel\Ticket as TicketResource;

/**
 * Remove a ticket and everything hanging off it.
 *
 * The most destructive tool in this domain, and the one with the least to
 * recommend it: the messages, the attachments and the custom field values all
 * go with the ticket, the customer keeps whatever they were emailed, and there
 * is no undo. Spam that arrived before a pattern caught it is the case it
 * exists for.
 *
 * Almost everything else people mean by "get rid of this ticket" is the spam or
 * archive folder — which keeps the record, takes it out of the queue, and can
 * be undone. The description says so, because an agent reaching for delete is
 * usually reaching for that.
 */
class DeleteHelpdeskTicket extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketResource $ticketResource
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketResource $ticketResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete one ticket, with its messages, attachments and custom field '
            . 'values. There is no undo and the customer keeps any email already sent. This is for '
            . 'spam and for a ticket opened in error — to take a real ticket out of the queue '
            . 'while keeping the record, set its status instead, which is reversible.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
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
    public function isWrite(): bool
    {
        return true;
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

        $ticketId = (int) $ticket->getId();
        $code = (string) $ticket->getData('code');
        $subject = (string) $ticket->getData('subject');

        $this->ticketResource->delete($ticket);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            // Echoed back because after this call there is nothing left to look
            // them up from, and the audit log line is the only remaining record.
            'ticket_id' => $ticketId,
            'code' => $code,
            'subject' => $subject,
        ];
    }
}
