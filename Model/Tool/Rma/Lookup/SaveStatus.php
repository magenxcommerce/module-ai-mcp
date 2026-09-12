<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\Data\StatusInterfaceFactory;
use Magenx\Rma\Api\StatusRepositoryInterface;

/**
 * Create or update an RMA status.
 */
class SaveStatus extends AbstractLookupSave
{
    /**
     * @param StatusRepositoryInterface $repository
     * @param StatusInterfaceFactory $entityFactory
     */
    public function __construct(
        private readonly StatusRepositoryInterface $repository,
        private readonly StatusInterfaceFactory $entityFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_rma_status';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_status';
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
    protected function entityNameSingular(): string
    {
        return 'status';
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
