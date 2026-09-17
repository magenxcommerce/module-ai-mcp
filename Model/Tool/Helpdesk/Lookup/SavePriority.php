<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\PriorityFactory;
use Magenx\Helpdesk\Model\ResourceModel\Priority as ResourceModel;

/**
 * Create or edit a ticket priority.
 *
 * The least entangled of the writable lookups: a priority is a label, a colour
 * and a position, referenced by id from tickets and by nothing in store
 * configuration.
 */
class SavePriority extends AbstractHelpdeskSave
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
        return 'save_helpdesk_priority';
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
    protected function fields(): array
    {
        return [
            'code' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'Machine name, unique among priorities. Changing it on a priority '
                    . 'already in use is how references break, so prefer leaving it alone.',
            ],
            'label' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'description' => 'What an operator reads in the ticket grid.',
            ],
            'color' => [
                'type' => self::TYPE_STRING,
                'description' => 'Hex colour used for the badge, e.g. "#c0392b".',
            ],
            'sort_order' => [
                'type' => self::TYPE_INT,
                'description' => 'Position in the list, lower first.',
            ],
            'is_active' => [
                'type' => self::TYPE_BOOL,
                'description' => 'Whether the priority is offered on new tickets.',
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
            'priority_id' => (int) $row->getId(),
            'code' => $row->getData('code'),
            'label' => $row->getData('label'),
            'color' => $row->getData('color'),
            'is_active' => (bool) $row->getData('is_active'),
            'sort_order' => (int) $row->getData('sort_order'),
        ];
    }
}
