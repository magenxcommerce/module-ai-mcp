<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\DepartmentFactory;
use Magenx\Helpdesk\Model\ResourceModel\Department as ResourceModel;

/**
 * Create or edit a department.
 *
 * Departments route tickets and own the members notified about them. Creating
 * one is harmless; retiring one is what `is_active` is for, because a gateway
 * delivering into a department that no longer exists has nowhere to put mail.
 */
class SaveDepartment extends AbstractHelpdeskSave
{
    /**
     * @param DepartmentFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly DepartmentFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_helpdesk_department';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::department';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'departments';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'department';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'department_id';
    }

    /**
     * @inheritDoc
     */
    protected function fields(): array
    {
        return [
            'title' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'What operators and, where shown, customers read.',
            ],
            'sender_email' => [
                'type' => self::TYPE_STRING,
                'description' => 'Address replies from this department are sent as. Leave unset to '
                    . 'use the store default.',
            ],
            'sort_order' => [
                'type' => self::TYPE_INT,
                'description' => 'Position in the list, lower first.',
            ],
            'is_active' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the department takes new tickets.',
            ],
            'is_visible_frontend' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether customers can pick it when opening a ticket.',
            ],
            'notify_all_members' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Notify every member on a new ticket rather than only the '
                    . 'assignee.',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function newRow(): object
    {
        return $this->factory->create();
    }

    /**
     * @inheritDoc
     */
    protected function loadRow(int $rowId): ?object
    {
        $row = $this->factory->create();
        $this->resource->load($row, $rowId);

        return $row->getId() ? $row : null;
    }

    /**
     * @inheritDoc
     */
    protected function saveRow(object $row): void
    {
        $this->resource->save($row);
    }

    /**
     * @inheritDoc
     */
    protected function projectRow(object $row): array
    {
        return [
            'department_id' => (int) $row->getId(),
            'title' => $row->getData('title'),
            'is_active' => (bool) $row->getData('is_active'),
            'is_visible_frontend' => (bool) $row->getData('is_visible_frontend'),
            'sort_order' => (int) $row->getData('sort_order'),
            'sender_email' => $row->getData('sender_email'),
            'notify_all_members' => (bool) $row->getData('notify_all_members'),
        ];
    }
}
