<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Delete a return request.
 */
class DeleteRma extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RMARepositoryInterface $rmaRepository
     * @param RmaProjector $projector
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RMARepositoryInterface $rmaRepository,
        private readonly RmaProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_rma';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a return request with its lines, comments and attachments. '
            . 'This cannot be undone, and it destroys the record of what the customer asked for — '
            . 'the order it came from is untouched. Almost always the wrong tool: moving the '
            . 'return to a cancelled or rejected status with update_rma keeps the history and is '
            . 'what the customer sees. Use this only to remove something created in error.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
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
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        // Captured before the delete so the result and the audit log name the
        // return that is now gone, not just the identifier passed in.
        $deleted = $this->projector->toArray($rma);

        $this->rmaRepository->delete($rma);

        return ['deleted' => true, 'tool' => $this->getName()] + $deleted;
    }
}
