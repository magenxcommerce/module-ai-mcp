<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\Data\ReasonInterfaceFactory;
use Magenx\Rma\Api\ReasonRepositoryInterface;

/**
 * Create or update an RMA reason.
 */
class SaveReason extends AbstractLookupSave
{
    /**
     * @param ReasonRepositoryInterface $repository
     * @param ReasonInterfaceFactory $entityFactory
     */
    public function __construct(
        private readonly ReasonRepositoryInterface $repository,
        private readonly ReasonInterfaceFactory $entityFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_rma_reason';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_reason';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'reasons';
    }

    /**
     * @inheritDoc
     */
    protected function entityNameSingular(): string
    {
        return 'reason';
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
