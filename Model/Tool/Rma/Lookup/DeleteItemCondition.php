<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ItemConditionRepositoryInterface;

/**
 * Delete an RMA item condition.
 */
class DeleteItemCondition extends AbstractLookupDelete
{
    /**
     * @param ItemConditionRepositoryInterface $repository
     */
    public function __construct(
        private readonly ItemConditionRepositoryInterface $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma_item_condition';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_item_condition';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'item conditions';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'item condition';
    }

    /**
     * @inheritDoc
     */
    protected function loadEntity(int $entityId): object
    {
        return $this->repository->get($entityId);
    }

    /**
     * @inheritDoc
     */
    protected function deleteEntity(object $entity): bool
    {
        return $this->repository->delete($entity);
    }
}
