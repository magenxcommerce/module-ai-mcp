<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\Status\CollectionFactory;

/**
 * List the help desk ticket statuses.
 */
class ListStatuses extends AbstractHelpdeskList
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
        return 'list_helpdesk_statuses';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the help desk statuses with their ids and codes. The ids are what '
            . 'set_helpdesk_ticket_status takes. Read-only, unlike the other lookups here: a '
            . 'status code is referenced from store configuration, which decides which statuses '
            . 'archive a ticket and which lock it, so creating or renaming one changes what '
            . 'closing a ticket does. That belongs in the admin beside the settings it affects.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::status';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'statuses';
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
            'status_id' => (int) $row->getId(),
            // The code, not the id, is what store configuration references when
            // deciding which statuses archive and which lock a ticket.
            'code' => $row->getData('code'),
            'label' => $row->getData('label'),
            'color' => $row->getData('color'),
            'is_active' => (bool) $row->getData('is_active'),
            'sort_order' => (int) $row->getData('sort_order'),
        ];
    }
}
