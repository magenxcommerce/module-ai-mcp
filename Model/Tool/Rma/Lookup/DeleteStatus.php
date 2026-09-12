<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\StatusRepositoryInterface;

/**
 * Delete an RMA status.
 */
class DeleteStatus extends AbstractLookupDelete
{
    /**
     * @param StatusRepositoryInterface $repository
     */
    public function __construct(
        private readonly StatusRepositoryInterface $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma_status';
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
    protected function deleteEntity(object $entity): bool
    {
        return $this->repository->delete($entity);
    }
}
