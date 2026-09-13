<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one return request.
 */
class GetRma extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RmaProjector $projector
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RmaProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_rma';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one return request by increment_id or rma_id. Its lines are listed '
            . 'separately by list_rma_items and its correspondence by list_rma_comments, because '
            . 'either can be long.';
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
    public function execute(array $arguments): array
    {
        return $this->projector->toArray($this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        ));
    }
}
