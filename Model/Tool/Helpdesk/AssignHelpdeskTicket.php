<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\TicketManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Api\Data\UserInterface;
use Magento\User\Model\UserFactory;

/**
 * Assign a ticket to an admin user, or clear the assignment.
 */
class AssignHelpdeskTicket extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketManager $ticketManager
     * @param TicketProjector $projector
     * @param UserFactory $userFactory
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketManager $ticketManager,
        private readonly TicketProjector $projector,
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'assign_helpdesk_ticket';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Assign a ticket to an admin user, or pass null to leave it unassigned. Assigning '
            . 'it to someone new emails that person — staff, not the customer, who sees nothing. '
            . 'Re-assigning to whoever already holds it sends nothing.';
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
                    'admin_user_id' => [
                        'type' => ['integer', 'null'],
                        'description' => 'The admin user to assign it to. Null clears the '
                            . 'assignment and returns the ticket to the unassigned queue.',
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

        if (!array_key_exists('admin_user_id', $arguments)) {
            throw new LocalizedException(
                __('Pass "admin_user_id", or null to leave the ticket unassigned.')
            );
        }

        $userId = $arguments['admin_user_id'];
        $assignee = null;
        if ($userId !== null) {
            if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
                throw new LocalizedException(
                    __('The "admin_user_id" argument must be a whole number, or null to clear it.')
                );
            }
            $userId = (int) $userId;
            $assignee = $this->assertUserExists($userId);
        }

        $previous = $ticket->getData('user_id') === null ? null : (int) $ticket->getData('user_id');
        $updated = $this->ticketManager->assign($ticket, $userId);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'assigned_to' => $assignee,
            'previous_user_id' => $previous,
            // The module notifies the assignee only when the assignment
            // actually moves to someone.
            'assignee_notified' => $userId !== null && $userId !== $previous,
        ] + $this->projector->toArray($updated);
    }

    /**
     * Refuse an admin user id that does not exist.
     *
     * The column has nothing enforcing it, so an unknown id would store, the
     * ticket would read as assigned, and the notification would silently fail.
     *
     * @param int $userId
     * @return string
     * @throws LocalizedException
     */
    private function assertUserExists(int $userId): string
    {
        try {
            $user = $this->userFactory->create()->load($userId);
        } catch (NoSuchEntityException) {
            $user = null;
        }

        if ($user === null || !$user->getId()) {
            throw new LocalizedException(
                __('No admin user exists with admin_user_id %1.', $userId)
            );
        }

        /** @var UserInterface $user */
        return trim((string) $user->getFirstName() . ' ' . (string) $user->getLastName())
            ?: (string) $user->getUserName();
    }
}
