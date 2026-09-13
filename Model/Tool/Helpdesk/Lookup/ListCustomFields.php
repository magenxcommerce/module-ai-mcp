<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\Field\CollectionFactory;

/**
 * List the custom fields tickets can carry.
 */
class ListCustomFields extends AbstractHelpdeskList
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
        return 'list_helpdesk_custom_fields';
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
    protected function entityName(): string
    {
        return 'custom ticket fields';
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
            'field_id' => (int) $row->getId(),
            // The code is the key create_helpdesk_ticket takes in custom_fields.
            'code' => $row->getData('code'),
            'title' => $row->getData('title'),
            'type' => $row->getData('type'),
            'options' => $row->getData('options'),
            'description' => $row->getData('description'),
            'is_active' => (bool) $row->getData('is_active'),
            'is_visible_customer' => (bool) $row->getData('is_visible_customer'),
            'is_editable_customer' => (bool) $row->getData('is_editable_customer'),
            'is_required_customer' => (bool) $row->getData('is_required_customer'),
            'sort_order' => (int) $row->getData('sort_order'),
        ];
    }
}
