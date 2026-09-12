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
 * Reply to the customer on a ticket.
 *
 * Deliberately a separate tool from add_helpdesk_internal_note rather than one
 * tool with a visibility flag. The module stores both in the same table,
 * distinguished only by `type`, and its own comment warns that letting an
 * unrecognised type through would downgrade a staff-only note into something
 * the customer is e-mailed. Making the two a choice of tool rather than a
 * choice of argument means a mistake cannot turn a note into a reply.
 */
class ReplyToHelpdeskTicket extends AbstractTool
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
        return 'reply_to_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Post a public reply on a ticket, as staff. The customer can read it and, unless '
            . 'you set notify false, is emailed it — so this is visible to them the moment it '
            . 'succeeds. For a staff-only note use add_helpdesk_internal_note instead. Replying '
            . 'works on a locked ticket but does not unlock it: the customer still cannot answer '
            . 'until reopen_helpdesk_ticket.';
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
                    'body' => ['type' => 'string', 'description' => 'The reply text.'],
                    'notify' => [
                        'type' => 'boolean',
                        'description' => 'Email the reply to the customer. Default true — the reply '
                            . 'is visible in their account either way.',
                    ],
                    'author_name' => [
                        'type' => 'string',
                        'description' => 'Name shown against the reply. Defaults to "Support".',
                    ],
                    'admin_user_id' => [
                        'type' => 'integer',
                        'description' => 'Admin user to record as the author, where you want the '
                            . 'reply attributed to a person.',
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
    public function execute(array $arguments): array
    {
        $ticket = $this->locator->locate(
            $this->optionalString($arguments, 'code'),
            $this->optionalInt($arguments, 'ticket_id')
        );

        $body = $this->requireString($arguments, 'body');
        $notify = (bool) $this->optionalBool($arguments, 'notify', true);

        $message = $this->ticketManager->addMessage(
            $ticket,
            $body,
            Ticket::AUTHOR_STAFF,
            [
                'type' => Message::TYPE_PUBLIC,
                'author_name' => $this->optionalString($arguments, 'author_name', 'Support'),
                'user_id' => $this->optionalInt($arguments, 'admin_user_id'),
            ],
            $notify
        );

        return [
            'created' => true,
            'tool' => $this->getName(),
            'ticket_id' => (int) $ticket->getId(),
            'code' => $ticket->getCode(),
            'message_id' => (int) $message->getId(),
            'type' => $message->getData('type'),
            'visible_to_customer' => true,
            'customer_notified' => $notify,
            // A public reply moves the ticket's last-reply markers; an internal
            // note would not.
            'last_reply_by' => $ticket->getData('last_reply_by'),
        ];
    }
}
