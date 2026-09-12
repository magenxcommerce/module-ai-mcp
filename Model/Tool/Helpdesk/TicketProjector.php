<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\Helpdesk\Model\Ticket;

/**
 * Presents a ticket.
 *
 * Department, status and priority are ids into the module's own tables, so they
 * are reported as ids and the list tools turn them into labels — resolving each
 * one here would mean three extra queries per row on a listing.
 *
 * The subject and the customer's name and address are in every row because they
 * are what identifies a ticket to an operator. The message bodies are not:
 * those are personal correspondence and can be long, so they come only from
 * list_helpdesk_ticket_messages.
 */
class TicketProjector
{
    /**
     * @param Ticket $ticket
     * @return array<string, mixed>
     */
    public function toArray(Ticket $ticket): array
    {
        return [
            'ticket_id' => (int) $ticket->getId(),
            'code' => $ticket->getCode(),
            'subject' => $ticket->getData('subject'),
            'customer_email' => $ticket->getData('customer_email'),
            'customer_name' => $ticket->getData('customer_name'),
            'customer_id' => $this->nullableInt($ticket->getData('customer_id')),
            'order_id' => $this->nullableInt($ticket->getData('order_id')),
            'department_id' => $this->nullableInt($ticket->getData('department_id')),
            'status_id' => $this->nullableInt($ticket->getData('status_id')),
            'priority_id' => $this->nullableInt($ticket->getData('priority_id')),
            // The admin user the ticket is assigned to, or null for unassigned.
            'assigned_user_id' => $this->nullableInt($ticket->getData('user_id')),
            'store_id' => $this->nullableInt($ticket->getData('store_id')),
            // How the ticket arrived: customer_account, email, contact_form or
            // backend.
            'channel' => $ticket->getData('channel'),
            // inbox, archive or spam. A status change can move a ticket between
            // inbox and archive; spam is only ever set deliberately.
            'folder' => $ticket->getData('folder'),
            // True means the customer can no longer reply. Staff still can.
            'is_locked' => $ticket->isLocked(),
            'last_reply_by' => $ticket->getData('last_reply_by'),
            'last_reply_at' => $ticket->getData('last_reply_at'),
            'created_at' => $ticket->getData('created_at'),
            'updated_at' => $ticket->getData('updated_at'),
        ];
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
