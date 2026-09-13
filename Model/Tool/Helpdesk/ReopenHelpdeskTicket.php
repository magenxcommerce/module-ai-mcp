<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\TicketManager;

/**
 * Reopen a locked ticket.
 */
class ReopenHelpdeskTicket extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketManager $ticketManager
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketManager $ticketManager,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'reopen_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Reopen a ticket: move it to the store\'s default status, unlock it so the customer '
            . 'can reply again, and bring it back to the inbox. Safe to call on a ticket that is '
            . 'already open — it simply resets it to the default status. Sends no email.';
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

        $wasLocked = $ticket->isLocked();
        $folderBefore = (string) $ticket->getData('folder');

        $updated = $this->ticketManager->reopen($ticket);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'was_locked' => $wasLocked,
            'folder_before' => $folderBefore,
        ] + $this->projector->toArray($updated);
    }
}
