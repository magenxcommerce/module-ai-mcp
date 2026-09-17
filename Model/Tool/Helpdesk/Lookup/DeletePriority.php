<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\PriorityFactory;
use Magenx\Helpdesk\Model\ResourceModel\Priority as ResourceModel;

/**
 * Remove a ticket priority.
 */
class DeletePriority extends AbstractHelpdeskDelete
{
    /**
     * @param PriorityFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly PriorityFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_helpdesk_priority';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::priority';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'priority';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'priority_id';
    }

    /**
     * @inheritDoc
     */
    protected function orphanWarning(): string
    {
        return 'Tickets already set to it keep the id and show a blank priority.';
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
