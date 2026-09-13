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
 * Shared writing for the RMA module's four lookup tables.
 *
 * Creates when entity_id is absent and updates when it is present, which is the
 * module's own admin behaviour: its lookup save controller decides the same way
 * and merges the submitted fields onto the loaded row. An update here loads the
 * row first so a field that was not passed keeps its value.
 */
abstract class AbstractLookupSave extends AbstractTool
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
     * Load one row for update.
     *
     * @param int $entityId
     * @return object
     * @throws NoSuchEntityException
     */
    abstract protected function loadEntity(int $entityId): object;

    /**
     * A new, empty row.
     *
     * @return object
     */
    abstract protected function newEntity(): object;

    /**
     * @param object $entity
     * @return object
     */
    abstract protected function saveEntity(object $entity): object;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Create or update one RMA %s. Omit entity_id to create a new row; pass it to change '
            . 'an existing one, in which case only the fields you pass are changed. Deactivating '
            . 'a row with is_active false hides it from new returns without touching returns that '
            . 'already use it, which is the safe way to retire one.',
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
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'The row to change. Omit to create a new one.',
                ],
                'code' => [
                    'type' => 'string',
                    'description' => 'Machine name, unique within this lookup. Required when '
                        . 'creating. Changing it on a row already in use is how references break, '
                        . 'so prefer leaving it alone.',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'What an operator and, where shown, the customer reads. '
                        . 'Required when creating.',
                ],
                'is_active' => ['type' => 'boolean', 'description' => 'Whether the row is offered.'],
                'sort_order' => [
                    'type' => 'integer',
                    'description' => 'Position in the list, lower first.',
                ],
                'store_labels' => [
                    'type' => 'object',
                    'description' => 'Per-store-view label overrides, keyed by store id as a '
                        . 'string, e.g. {"3": "Remboursement"}. Replaces the whole set. '
                        . 'list_stores reports the ids.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
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
        $entityId = $this->optionalInt($arguments, 'entity_id');
        $isNew = $entityId === null;

        if ($isNew) {
            $entity = $this->newEntity();
            // Only a create can require these: an update that demanded them
            // would make changing a sort order mean restating the label.
            $entity->setCode($this->requireString($arguments, 'code'));
            $entity->setLabel($this->requireString($arguments, 'label'));
        } else {
            try {
                $entity = $this->loadEntity($entityId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('No RMA %1 exists with entity_id %2.', $this->entityNameSingular(), $entityId)
                );
            }
        }

        $changed = $this->apply($entity, $arguments, $isNew);
        if (!$isNew && $changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides entity_id.')
            );
        }

        $saved = $this->saveEntity($entity);

        return [
            $isNew ? 'created' : 'updated' => true,
            'tool' => $this->getName(),
            'entity' => $this->entityName(),
            'entity_id' => $saved->getEntityId(),
            'code' => $saved->getCode(),
            'label' => $saved->getLabel(),
            'is_active' => (bool) $saved->getIsActive(),
            'sort_order' => $saved->getSortOrder(),
            'changed_fields' => $changed,
        ];
    }

    /**
     * @param object $entity
     * @param array<string, mixed> $arguments
     * @param bool $isNew
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(object $entity, array $arguments, bool $isNew): array
    {
        $changed = $isNew ? ['code', 'label'] : [];

        if (!$isNew) {
            foreach (['code' => 'setCode', 'label' => 'setLabel'] as $key => $setter) {
                if (!array_key_exists($key, $arguments)) {
                    continue;
                }
                $value = $arguments[$key];
                if (!is_string($value) || trim($value) === '') {
                    throw new LocalizedException(__('The "%1" argument must be a non-empty string.', $key));
                }
                $entity->{$setter}(trim($value));
                $changed[] = $key;
            }
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(__(
                    'The "%1" argument must be true or false, not a string or a number.',
                    'is_active'
                ));
            }
            // Stored as an int by this module's data contract.
            $entity->setIsActive($arguments['is_active'] ? 1 : 0);
            $changed[] = 'is_active';
        }

        if (array_key_exists('sort_order', $arguments)) {
            $sortOrder = $arguments['sort_order'];
            if (!is_int($sortOrder) && !(is_string($sortOrder) && ctype_digit($sortOrder))) {
                throw new LocalizedException(__('The "sort_order" argument must be a whole number.'));
            }
            $entity->setSortOrder((int) $sortOrder);
            $changed[] = 'sort_order';
        }

        if (array_key_exists('store_labels', $arguments)) {
            $entity->setStoreLabels($this->storeLabels($arguments['store_labels']));
            $changed[] = 'store_labels';
        }

        return $changed;
    }

    /**
     * @param mixed $storeLabels
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function storeLabels(mixed $storeLabels): array
    {
        if (!is_array($storeLabels)) {
            throw new LocalizedException(__(
                'The "store_labels" argument must be an object keyed by store id, or an empty '
                . 'object to clear the overrides.'
            ));
        }

        $labels = [];
        foreach ($storeLabels as $storeId => $label) {
            if (!is_int($storeId) && !(is_string($storeId) && ctype_digit($storeId))) {
                throw new LocalizedException(
                    __('Every key in "store_labels" must be a store id. Got "%1".', (string) $storeId)
                );
            }
            if (!is_string($label)) {
                throw new LocalizedException(
                    __('The "store_labels" entry for store %1 must be a string.', (string) $storeId)
                );
            }
            $labels[(int) $storeId] = $label;
        }

        return $labels;
    }
}
