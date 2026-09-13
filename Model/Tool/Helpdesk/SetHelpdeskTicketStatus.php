<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\ResourceModel\Status\CollectionFactory as StatusCollectionFactory;
use Magenx\Helpdesk\Model\TicketManager;
use Magento\Framework\Exception\LocalizedException;

/**
 * Move a ticket to a status.
 *
 * A status is not just a label here: the module reads the store's configured
 * archive and lock status codes and, on the way through, may file the ticket
 * into Archive and stop the customer replying. Those are the consequences worth
 * naming in the description, because "set status to closed" reads harmless.
 */
class SetHelpdeskTicketStatus extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketManager $ticketManager
     * @param TicketProjector $projector
     * @param StatusCollectionFactory $statusCollectionFactory
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketManager $ticketManager,
        private readonly TicketProjector $projector,
        private readonly StatusCollectionFactory $statusCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_helpdesk_ticket_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move a ticket to a status. Depending on how the store configures its statuses this '
            . 'can also file the ticket into Archive and lock it, which stops the customer '
            . 'replying — the result reports the folder and lock state it ended up in. Moving off '
            . 'an archiving status brings the ticket back to the inbox; a ticket in Spam stays '
            . 'there, since taking it out is a deliberate act. Changing status sends no email on '
            . 'its own.';
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
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'The new status; list_helpdesk_statuses reports the ids.',
                    ],
                ]
            ),
            'required' => ['status_id'],
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
        $statusId = $this->requireInt($arguments, 'status_id');

        // Nothing in the schema enforces this column, and the module resolves an
        // unknown id to a null code — which would skip the archive and lock
        // rules entirely and leave the ticket on a status that renders blank.
        $status = $this->statusCollectionFactory->create()
            ->addFieldToFilter('status_id', $statusId)
            ->getFirstItem();
        if (!$status->getId()) {
            throw new LocalizedException(__(
                'No such status_id: %1. list_helpdesk_statuses reports the ids.',
                $statusId
            ));
        }

        $folderBefore = (string) $ticket->getData('folder');
        $wasLocked = $ticket->isLocked();

        $updated = $this->ticketManager->changeStatus($ticket, $statusId);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'status_code' => $status->getData('code'),
            'status_label' => $status->getData('label'),
            'folder_before' => $folderBefore,
            'was_locked' => $wasLocked,
        ] + $this->projector->toArray($updated);
    }
}
