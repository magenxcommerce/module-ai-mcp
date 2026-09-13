<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\ResourceModel\Priority\CollectionFactory as PriorityCollectionFactory;
use Magenx\Helpdesk\Model\TicketManager;
use Magento\Framework\Exception\LocalizedException;

/**
 * Set a ticket's priority.
 */
class SetHelpdeskTicketPriority extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketManager $ticketManager
     * @param TicketProjector $projector
     * @param PriorityCollectionFactory $priorityCollectionFactory
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketManager $ticketManager,
        private readonly TicketProjector $projector,
        private readonly PriorityCollectionFactory $priorityCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_helpdesk_ticket_priority';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set a ticket\'s priority, or pass null to clear it. Priority is triage metadata '
            . 'only: unlike status it carries no archive or lock rules, changes nothing the '
            . 'customer sees, and sends no email.';
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
                    'priority_id' => [
                        'type' => ['integer', 'null'],
                        'description' => 'The new priority; list_helpdesk_priorities reports the '
                            . 'ids. Null clears it.',
                    ],
                ]
            ),
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

        if (!array_key_exists('priority_id', $arguments)) {
            throw new LocalizedException(
                __('Pass "priority_id", or null to clear the ticket\'s priority.')
            );
        }

        $priorityId = $arguments['priority_id'];
        if ($priorityId !== null) {
            if (!is_int($priorityId) && !(is_string($priorityId) && ctype_digit($priorityId))) {
                throw new LocalizedException(
                    __('The "priority_id" argument must be a whole number, or null to clear it.')
                );
            }
            $priorityId = (int) $priorityId;

            $priority = $this->priorityCollectionFactory->create()
                ->addFieldToFilter('priority_id', $priorityId)
                ->getFirstItem();
            if (!$priority->getId()) {
                throw new LocalizedException(__(
                    'No such priority_id: %1. list_helpdesk_priorities reports the ids.',
                    $priorityId
                ));
            }
        }

        $updated = $this->ticketManager->changePriority($ticket, $priorityId);

        return [
            'updated' => true,
            'tool' => $this->getName(),
        ] + $this->projector->toArray($updated);
    }
}
