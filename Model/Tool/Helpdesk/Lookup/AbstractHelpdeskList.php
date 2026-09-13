<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Shared reading for the help desk module's configuration tables.
 *
 * Paging, the active filter and the ordering are the same for all of them; the
 * rows are not, so each subclass projects its own. Reads go through the
 * module's collections because it has no service-contract layer — the same
 * compromise the ticket search makes, and the reason these are read-only.
 *
 * Writing any of these is deliberately not exposed. Statuses in particular are
 * referenced by *code* from store configuration, which decides which of them
 * archive and which lock a ticket, so creating or renaming one through a tool
 * can change what closing a ticket does. That belongs in the admin, next to the
 * settings it interacts with.
 */
abstract class AbstractHelpdeskList extends AbstractTool
{
    /**
     * What these rows are called, for the description and the result.
     *
     * @return string
     */
    abstract protected function entityName(): string;

    /**
     * A collection of this lookup's rows.
     *
     * @return object
     */
    abstract protected function createCollection(): object;

    /**
     * Reduce one row to what is worth sending.
     *
     * @param object $row
     * @return array<string, mixed>
     */
    abstract protected function projectRow(object $row): array;

    /**
     * The column rows are ordered by. Not every table has a sort order.
     *
     * @return string
     */
    protected function orderColumn(): string
    {
        return 'sort_order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'List the help desk %s with their ids. The ids are what the ticket tools take, so '
            . 'this is how to turn one into a label or find the id to set. Read-only: these are '
            . 'workflow configuration and are edited in the admin.',
            $this->entityName()
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Restrict to active or inactive rows.',
                    ],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->createCollection();

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $collection->setOrder($this->orderColumn(), 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = $this->projectRow($row);
        }

        return [
            'entity' => $this->entityName(),
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
