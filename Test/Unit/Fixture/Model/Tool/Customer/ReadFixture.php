<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * A read returning two records, one identified by increment id and one by
 * entity id, each with a field the audit must not copy.
 */
class ReadFixture extends AbstractTool
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_people';
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
        return ['type' => 'object', 'properties' => []];
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
        return [
            'total_count' => 2,
            'page' => 1,
            'page_size' => 20,
            'items' => [
                ['increment_id' => '000000042', 'street' => '1 Main Street'],
                ['entity_id' => 7, 'street' => '2 Main Street'],
            ],
        ];
    }
}
