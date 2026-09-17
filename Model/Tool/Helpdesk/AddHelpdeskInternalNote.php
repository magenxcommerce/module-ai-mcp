<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\Message;
use Magenx\Helpdesk\Model\Ticket;
use Magenx\Helpdesk\Model\TicketManager;

/**
 * Add a staff-only note to a ticket.
 *
 * The counterpart to reply_to_helpdesk_ticket. Separate tools rather than one
 * with a visibility flag, so that the customer-visible decision is made by
 * choosing a tool and cannot be got wrong by an argument.
 */
class AddHelpdeskInternalNote extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketManager $ticketManager
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketManager $ticketManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_helpdesk_internal_note';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a staff-only note to a ticket. The customer never sees it and is never '
            . 'emailed, and it does not count as a reply — the ticket\'s last-reply markers stay '
            . 'where they were, so an unanswered ticket still reads as unanswered. Use '
            . 'reply_to_helpdesk_ticket to write to the customer.';
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
                    'body' => ['type' => 'string', 'description' => 'The note text.'],
                    'author_name' => [
                        'type' => 'string',
                        'description' => 'Name shown against the note. Defaults to "Support".',
                    ],
                    'admin_user_id' => [
                        'type' => 'integer',
                        'description' => 'Admin user to record as the author.',
                    ],
                ]
            ),
            'required' => ['body'],
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
    protected function isDestructive(): bool
    {
        // Appends a note; nothing already on the ticket changes.
        return false;
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

        $body = $this->requireString($arguments, 'body');

        // The module ignores the notify flag for an internal note, so passing
        // false here is belt and braces rather than the thing that keeps it
        // quiet.
        $message = $this->ticketManager->addMessage(
            $ticket,
            $body,
            Ticket::AUTHOR_STAFF,
            [
                'type' => Message::TYPE_INTERNAL,
                'author_name' => $this->optionalString($arguments, 'author_name', 'Support'),
                'user_id' => $this->optionalInt($arguments, 'admin_user_id'),
            ],
            false
        );

        return [
            'created' => true,
            'tool' => $this->getName(),
            'ticket_id' => (int) $ticket->getId(),
            'code' => $ticket->getCode(),
            'message_id' => (int) $message->getId(),
            'type' => $message->getData('type'),
            'visible_to_customer' => false,
            'customer_notified' => false,
        ];
    }
}
