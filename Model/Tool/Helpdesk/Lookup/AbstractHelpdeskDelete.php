<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared deletion for the writable help desk lookups.
 *
 * Every description here points at deactivating instead, and means it: a
 * priority or a department is referenced by id from tickets that already exist,
 * and removing the row does not remove those references — it leaves tickets
 * pointing at nothing, which reads in the admin as a blank column rather than
 * as an error. Deactivating keeps the label resolvable and takes the row out of
 * new tickets, which is what "retire this" almost always means.
 *
 * Delete is still offered, because a row created by mistake five minutes ago
 * should not have to be carried forever.
 */
abstract class AbstractHelpdeskDelete extends AbstractTool
{
    /**
     * @return string
     */
    abstract protected function entityNameSingular(): string;

    /**
     * @return string
     */
    abstract protected function idArgument(): string;

    /**
     * What is left pointing at nothing when this row goes.
     *
     * @return string
     */
    abstract protected function orphanWarning(): string;

    /**
     * @param int $rowId
     * @return object|null
     */
    abstract protected function loadRow(int $rowId): ?object;

    /**
     * @param object $row
     * @return void
     */
    abstract protected function deleteRow(object $row): void;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Delete one help desk %s. %s Prefer setting is_active false instead, which retires the '
            . 'row without breaking anything that already refers to it. Deleting is for a row '
            . 'created in error.',
            $this->entityNameSingular(),
            $this->orphanWarning()
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                $this->idArgument() => [
                    'type' => 'integer',
                    'description' => sprintf('The %s to delete.', $this->entityNameSingular()),
                ],
            ],
            'required' => [$this->idArgument()],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rowId = $this->requireInt($arguments, $this->idArgument());

        $row = $this->loadRow($rowId);
        if ($row === null) {
            throw new LocalizedException(__(
                'No help desk %1 exists with %2 %3.',
                $this->entityNameSingular(),
                $this->idArgument(),
                $rowId
            ));
        }

        $this->deleteRow($row);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            $this->idArgument() => $rowId,
        ];
    }
}
