<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\Data\ResolutionTypeInterfaceFactory;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;

/**
 * Create or update an RMA resolution type.
 */
class SaveResolutionType extends AbstractLookupSave
{
    /**
     * @param ResolutionTypeRepositoryInterface $repository
     * @param ResolutionTypeInterfaceFactory $entityFactory
     */
    public function __construct(
        private readonly ResolutionTypeRepositoryInterface $repository,
        private readonly ResolutionTypeInterfaceFactory $entityFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_rma_resolution_type';
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
