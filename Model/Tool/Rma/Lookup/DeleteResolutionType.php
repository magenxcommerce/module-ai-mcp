<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;

/**
 * Delete an RMA resolution type.
 */
class DeleteResolutionType extends AbstractLookupDelete
{
    /**
     * @param ResolutionTypeRepositoryInterface $repository
     */
    public function __construct(
        private readonly ResolutionTypeRepositoryInterface $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma_resolution_type';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_resolution_type';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'resolution types';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'resolution type';
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
