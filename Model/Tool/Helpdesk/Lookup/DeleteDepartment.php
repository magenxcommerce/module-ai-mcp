<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\DepartmentFactory;
use Magenx\Helpdesk\Model\ResourceModel\Department as ResourceModel;

/**
 * Remove a department.
 */
class DeleteDepartment extends AbstractHelpdeskDelete
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
        return 'delete_helpdesk_department';
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
    protected function orphanWarning(): string
    {
        return 'Tickets in it are left unrouted, and any gateway delivering into it stops having a destination.';
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
    protected function deleteRow(object $row): void
    {
        $this->resource->delete($row);
    }
}
