<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\DepartmentFactory;
use Magenx\Helpdesk\Model\ResourceModel\Department as DepartmentResource;
use Magenx\Helpdesk\Model\ResourceModel\Ticket as TicketResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Move a ticket to another department.
 *
 * A tool of its own rather than another argument on assign_helpdesk_ticket,
 * which the plan first called for: that tool is about the assignee and its
 * whole description turns on who gets emailed when an assignment moves. Routing
 * a ticket to a different queue is a different act with different consequences,
 * and folding the two together would have made both descriptions hedge.
 */
class SetHelpdeskTicketDepartment extends AbstractTool
{
    /**
     * @param TicketLocator $locator
     * @param TicketResource $ticketResource
     * @param DepartmentFactory $departmentFactory
     * @param DepartmentResource $departmentResource
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly TicketLocator $locator,
        private readonly TicketResource $ticketResource,
        private readonly DepartmentFactory $departmentFactory,
        private readonly DepartmentResource $departmentResource,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_helpdesk_ticket_department';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move a ticket to a different department, which is how it reaches the right queue '
            . 'and the right members. list_helpdesk_departments reports the ids. This does not '
            . 'change who the ticket is assigned to — use assign_helpdesk_ticket for that, and note '
            . 'an assignee who is not a member of the new department keeps the ticket.';
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
                    'department_id' => [
                        'type' => 'integer',
                        'description' => 'The department to move it to. '
                            . 'list_helpdesk_departments reports the ids.',
                    ],
                ]
            ),
            'required' => ['department_id'],
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
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
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

        $departmentId = $this->requireInt($arguments, 'department_id');
        $department = $this->departmentFactory->create();
        $this->departmentResource->load($department, $departmentId);

        // Nothing in the schema enforces this column, so an unknown id would
        // store and the ticket would drop out of every queue at once.
        if (!$department->getId()) {
            throw new LocalizedException(__(
                'No help desk department exists with department_id %1. list_helpdesk_departments '
                . 'reports the ids.',
                $departmentId
            ));
        }

        $previous = $ticket->getData('department_id') === null
            ? null
            : (int) $ticket->getData('department_id');

        $ticket->setData('department_id', $departmentId);
        $this->ticketResource->save($ticket);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'department_id' => $departmentId,
            'department_title' => $department->getData('title'),
            'previous_department_id' => $previous,
        ] + $this->projector->toArray($ticket);
    }
}
