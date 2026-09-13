<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\Helpdesk\Model\Ticket;
use Magenx\Helpdesk\Model\TicketFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Turns the ticket argument every help desk tool accepts into a loaded ticket.
 *
 * The code is what a customer quotes and what the notification e-mails carry,
 * so it is the identifier an agent normally has; the numeric id is what the
 * grid reports. The module's own model offers a lookup for each.
 */
class TicketLocator
{
    /**
     * @param TicketFactory $ticketFactory
     */
    public function __construct(
        private readonly TicketFactory $ticketFactory
    ) {
    }

    /**
     * @param string|null $code
     * @param int|null $ticketId
     * @return Ticket
     * @throws LocalizedException
     */
    public function locate(?string $code, ?int $ticketId): Ticket
    {
        if ($ticketId !== null) {
            $ticket = $this->ticketFactory->create();
            $ticket->load($ticketId);
            if (!$ticket->getId()) {
                throw new LocalizedException(__('No ticket exists with ticket_id %1.', $ticketId));
            }

            return $ticket;
        }

        if ($code === null) {
            throw new LocalizedException(
                __('Pass code (the ticket reference the customer quotes) or ticket_id.')
            );
        }

        $ticket = $this->ticketFactory->create()->loadByCode($code);
        if (!$ticket->getId()) {
            throw new LocalizedException(__('No ticket exists with code "%1".', $code));
        }

        return $ticket;
    }

    /**
     * Schema fragment for the arguments that name a ticket.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'code' => [
                'type' => 'string',
                'description' => 'The ticket reference as the customer sees it. Either this or '
                    . 'ticket_id is required.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => 'Numeric ticket id, as search_helpdesk_tickets reports it.',
            ],
        ];
    }
}
