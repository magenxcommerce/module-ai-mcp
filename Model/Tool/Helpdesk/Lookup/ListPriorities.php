<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\Priority\CollectionFactory;

/**
 * List the help desk ticket priorities.
 */
class ListPriorities extends AbstractHelpdeskList
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
        return 'list_helpdesk_priorities';
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
    protected function entityName(): string
    {
        return 'priorities';
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
            'priority_id' => (int) $row->getId(),
            'code' => $row->getData('code'),
            'label' => $row->getData('label'),
            'color' => $row->getData('color'),
            'is_active' => (bool) $row->getData('is_active'),
            'sort_order' => (int) $row->getData('sort_order'),
        ];
    }
}
