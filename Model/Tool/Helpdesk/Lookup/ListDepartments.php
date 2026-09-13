<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\Department\CollectionFactory;

/**
 * List the help desk departments.
 */
class ListDepartments extends AbstractHelpdeskList
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_helpdesk_departments';
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
    protected function orderColumn(): string
    {
        return 'sort_order';
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(): object
    {
        return $this->collectionFactory->create();
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
