<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one ticket.
 */
class GetHelpdeskTicket extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one ticket by code or ticket_id: who it is from, which department, status and '
            . 'priority it carries, who it is assigned to, and whether it is locked. The '
            . 'conversation itself is list_helpdesk_ticket_messages.';
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
    public function execute(array $arguments): array
    {
        return $this->projector->toArray($this->locator->locate(
            $this->optionalString($arguments, 'code'),
            $this->optionalInt($arguments, 'ticket_id')
        ));
    }
}
