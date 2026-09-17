<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\FieldFactory;
use Magenx\Helpdesk\Model\ResourceModel\Field as ResourceModel;

/**
 * Remove a ticket custom field.
 */
class DeleteCustomField extends AbstractHelpdeskDelete
{
    /**
     * @param FieldFactory $factory
     * @param ResourceModel $resource
     */
    public function __construct(
        private readonly FieldFactory $factory,
        private readonly ResourceModel $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_helpdesk_custom_field';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::field';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'custom field';
    }

    /**
     * @inheritDoc
     */
    protected function idArgument(): string
    {
        return 'field_id';
    }

    /**
     * @inheritDoc
     */
    protected function orphanWarning(): string
    {
        return 'Every value customers have already entered for it goes with it.';
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
