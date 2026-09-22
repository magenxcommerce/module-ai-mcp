<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Fixture\Model\Tool;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Fixture sitting directly in `Model\Tool\`, with no domain directory.
 *
 * {@see \Magenx\AiMcp\Model\Tool\ToolCatalog} treats a segment as a domain only
 * when more namespace follows it, so this one falls back to vendor and module
 * rather than inventing a domain named after the class. That rule is what a
 * `\Proxy` suffix would defeat, which is what {@see RootTool\Proxy} is for.
 */
class RootTool extends AbstractTool
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'root_tool';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Fixture tool.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        return [];
    }
}
