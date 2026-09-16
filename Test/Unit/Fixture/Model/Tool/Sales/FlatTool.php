<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Fixture whose namespace is what {@see \Magenx\AiMcp\Model\Tool\ToolCatalog}
 * derives a domain from. It has to be a real named class: an anonymous one has
 * no namespace, so it could only ever exercise the fallback.
 */
class FlatTool extends AbstractTool
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'flat_tool';
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
