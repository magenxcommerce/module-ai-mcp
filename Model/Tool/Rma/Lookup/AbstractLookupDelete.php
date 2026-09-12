<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Shared deletion for the RMA module's four lookup tables.
 *
 * Nothing in the data model stops a row being deleted while returns still point
 * at it — the references are plain integer columns with no constraint — so a
 * delete here can leave existing returns showing a blank status or reason. The
 * tools say so and point at deactivating instead.
 */
abstract class AbstractLookupDelete extends AbstractTool
{
    /**
     * Plural name of these rows, for the result.
     *
     * @return string
     */
    abstract protected function entityName(): string;

    /**
     * Singular name, for messages about one row. Given separately rather than
     * derived: "statuses" does not become "status" by dropping a letter.
     *
     * @return string
     */
    abstract protected function entityNameSingular(): string;

    /**
     * @param int $entityId
     * @return object
     * @throws NoSuchEntityException
     */
    abstract protected function loadEntity(int $entityId): object;

    /**
     * @param object $entity
     * @return bool
     */
    abstract protected function deleteEntity(object $entity): bool;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Permanently delete one RMA %1$s. This cannot be undone, and nothing prevents it while '
            . 'returns still reference the row — those returns are left pointing at an id that no '
            . 'longer resolves, which reads as a blank %1$s. Deactivating it instead, with '
            . 'is_active false, stops it being offered on new returns and keeps existing ones '
            . 'readable.',
            $this->entityNameSingular()
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
                'entity_id' => ['type' => 'integer', 'description' => 'The row to delete.'],
            ],
            'required' => ['entity_id'],
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
        $entityId = $this->requireInt($arguments, 'entity_id');

        try {
            $entity = $this->loadEntity($entityId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('No RMA %1 exists with entity_id %2.', $this->entityNameSingular(), $entityId)
            );
        }

        // Captured before the delete so the result names what is now gone.
        $deleted = [
            'entity' => $this->entityName(),
            'entity_id' => $entity->getEntityId(),
            'code' => $entity->getCode(),
            'label' => $entity->getLabel(),
        ];

        $this->deleteEntity($entity);

        return ['deleted' => true, 'tool' => $this->getName()] + $deleted;
    }
}
