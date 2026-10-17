<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Test\Unit\RegisteredTools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What the tool descriptions cost a client's context window.
 *
 * Every description is sent in full on every `tools/list`, to every client,
 * whether or not the agent ever calls that tool. Across 250 tools that is
 * tens of thousands of tokens, and nothing stopped it growing: each new tool
 * added a paragraph and each paragraph was reasonable on its own. These
 * ceilings make growth a decision. Raising one is allowed — say why in the
 * commit — but it should not happen by accident.
 *
 * Only descriptions are measured. Input schemas are the other half of the
 * cost, but many are assembled from injected collaborators and cannot be
 * built here without the Magento object graph.
 *
 * Detail that does not change which tool an agent picks, or how it calls it,
 * belongs in the README rather than in the description.
 */
class ContextBudgetTest extends TestCase
{
    use RegisteredTools;

    /** Longest description any one tool may carry, in bytes. */
    private const MAX_DESCRIPTION_BYTES = 600;

    /** All descriptions together, in bytes. Roughly a quarter of that in tokens. */
    private const MAX_TOTAL_DESCRIPTION_BYTES = 79500;

    /**
     * @return void
     */
    public function testNoDescriptionExceedsTheCeiling(): void
    {
        $over = [];
        foreach ($this->descriptions() as $name => $description) {
            if (strlen($description) > self::MAX_DESCRIPTION_BYTES) {
                $over[$name] = strlen($description);
            }
        }

        $this->assertSame(
            [],
            $over,
            sprintf(
                'These descriptions exceed %d bytes. Move the detail to the README.',
                self::MAX_DESCRIPTION_BYTES
            )
        );
    }

    /**
     * @return void
     */
    public function testAllDescriptionsTogetherStayWithinTheBudget(): void
    {
        $total = array_sum(array_map('strlen', $this->descriptions()));

        $this->assertLessThanOrEqual(
            self::MAX_TOTAL_DESCRIPTION_BYTES,
            $total,
            'Tool descriptions together are over budget. Trim before raising the ceiling.'
        );
    }

    /**
     * Every registered tool's description, read without building the tool:
     * every description in this module is a literal return.
     *
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        $descriptions = [];
        foreach ($this->registeredTools() as $name => $class) {
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(ToolInterface::class, $tool);
            $descriptions[$name] = $tool->getDescription();
        }

        return $descriptions;
    }
}
