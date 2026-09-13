<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\Rma\Api\ReasonRepositoryInterface;

/**
 * Delete an RMA reason.
 */
class DeleteReason extends AbstractLookupDelete
{
    /**
     * @param ReasonRepositoryInterface $repository
     */
    public function __construct(
        private readonly ReasonRepositoryInterface $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma_reason';
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
    protected function deleteEntity(object $entity): bool
    {
        return $this->repository->delete($entity);
    }
}
