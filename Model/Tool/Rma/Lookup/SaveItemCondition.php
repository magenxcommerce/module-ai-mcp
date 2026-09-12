<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\Data\ItemConditionInterfaceFactory;
use Magenx\Rma\Api\ItemConditionRepositoryInterface;

/**
 * Create or update an RMA item condition.
 */
class SaveItemCondition extends AbstractLookupSave
{
    /**
     * @param ItemConditionRepositoryInterface $repository
     * @param ItemConditionInterfaceFactory $entityFactory
     */
    public function __construct(
        private readonly ItemConditionRepositoryInterface $repository,
        private readonly ItemConditionInterfaceFactory $entityFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_rma_item_condition';
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
    protected function newEntity(): object
    {
        return $this->entityFactory->create();
    }

    /**
     * @inheritDoc
     */
    protected function saveEntity(object $entity): object
    {
        return $this->repository->save($entity);
    }
}
