<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\Ticket;
use Magenx\Helpdesk\Model\TicketManager;
use Magento\Framework\Exception\LocalizedException;

/**
 * Open a ticket on a customer's behalf.
 */
class CreateHelpdeskTicket extends AbstractTool
{
    /**
     * @param TicketManager $ticketManager
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly TicketManager $ticketManager,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Open a ticket on a customer\'s behalf — the case someone phoned in, or work you '
            . 'want tracked. It is recorded as arriving through the backend channel. The module '
            . 'sends its new-ticket notification and that cannot be suppressed here, so the '
            . 'customer hears about it. Status and priority default to the store\'s configured '
            . 'defaults when not given.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'The ticket subject.'],
                'body' => ['type' => 'string', 'description' => 'The opening message.'],
                'customer_email' => [
                    'type' => 'string',
                    'description' => 'Who the ticket is for. Where the notification goes.',
                ],
                'customer_name' => ['type' => 'string'],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Link it to a customer account, so it appears in their '
                        . 'account. Omit for a guest.',
                ],
                'order_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric order entity id this is about, as search_orders '
                        . 'reports under order_id.',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'list_helpdesk_departments reports the ids.',
                ],
                'status_id' => [
                    'type' => 'integer',
                    'description' => 'Defaults to the store\'s configured default status.',
                ],
                'priority_id' => [
                    'type' => 'integer',
                    'description' => 'Defaults to the store\'s configured default priority.',
                ],
                'store_id' => [
                    'type' => 'integer',
                    'description' => 'Store view the ticket belongs to, which decides the language '
                        . 'of its emails and which defaults apply. list_stores reports the ids.',
                ],
                'author_type' => [
                    'type' => 'string',
                    'enum' => ['customer', 'staff'],
                    'description' => 'Who the opening message is attributed to. Defaults to '
                        . '"customer", which is right for a case they reported; "staff" for one '
                        . 'you are raising yourself.',
                ],
                'custom_fields' => [
                    'type' => 'object',
                    'description' => 'Values for the store\'s custom ticket fields, keyed by field '
                        . 'code. list_helpdesk_custom_fields reports the codes.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['subject', 'body', 'customer_email'],
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
        // Adds a ticket; nothing that already exists is touched.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $authorType = strtolower((string) $this->optionalString($arguments, 'author_type', 'customer'));
        if (!in_array($authorType, ['customer', 'staff'], true)) {
            throw new LocalizedException(__('The "author_type" argument must be "customer" or "staff".'));
        }

        $data = [
            'subject' => $this->requireString($arguments, 'subject'),
            'customer_email' => $this->requireString($arguments, 'customer_email'),
            'customer_name' => $this->optionalString($arguments, 'customer_name'),
            'customer_id' => $this->optionalInt($arguments, 'customer_id'),
            'order_id' => $this->optionalInt($arguments, 'order_id'),
            'department_id' => $this->optionalInt($arguments, 'department_id'),
            'store_id' => $this->optionalInt($arguments, 'store_id') ?? 0,
            // Recorded as raised from the admin side whatever the message is
            // attributed to, so the grid and any channel reporting stay honest.
            'channel' => Ticket::CHANNEL_BACKEND,
            'author_type' => $authorType === 'staff' ? Ticket::AUTHOR_STAFF : Ticket::AUTHOR_CUSTOMER,
        ];

        // Passed only when given: the module fills either from the store's
        // configured default, and a null would override that with nothing.
        foreach (['status_id', 'priority_id'] as $key) {
            $value = $this->optionalInt($arguments, $key);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        $ticket = $this->ticketManager->createTicket(
            $data,
            $this->requireString($arguments, 'body'),
            $this->customFields($arguments)
        );

        return [
            'created' => true,
            'tool' => $this->getName(),
            'customer_notified' => true,
        ] + $this->projector->toArray($ticket);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function customFields(array $arguments): array
    {
        $fields = $arguments['custom_fields'] ?? null;
        if ($fields === null) {
            return [];
        }
        if (!is_array($fields)) {
            throw new LocalizedException(
                __('The "custom_fields" argument must be an object keyed by field code.')
            );
        }

        $values = [];
        foreach ($fields as $code => $value) {
            if (!is_string($code) || $code === '') {
                throw new LocalizedException(__('Every key in "custom_fields" must be a field code.'));
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new LocalizedException(
                    __('The "custom_fields" value for "%1" must be a string.', $code)
                );
            }
            $values[$code] = (string) $value;
        }

        return $values;
    }
}
