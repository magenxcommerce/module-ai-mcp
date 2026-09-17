<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Api\StructuredToolInterface;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Who promises an output shape, and whether the promise is true.
 *
 * An `outputSchema` is enforced in a way the annotations are not: MCP obliges
 * every successful `structuredContent` to validate against it, so a tool that
 * declares the shared envelope and then returns something else turns a response
 * a client would have accepted into one it rejects. That makes both directions
 * worth checking, and neither is checkable from a list kept by hand — a list of
 * forty-three names is exactly what went stale across four phases and produced
 * the annotation sweep.
 *
 * So the expectation is derived from the tools' own source: a tool whose
 * `execute()` builds the four envelope keys must declare the envelope, and a
 * tool declaring it must build them. The exclusions are named here with their
 * reasons, so dropping one is a deliberate act rather than a drift.
 *
 * @see \Magenx\AiMcp\Model\Tool\AbstractTool::searchEnvelopeSchema
 */
class OutputSchemaConventionsTest extends TestCase
{
    /** The literals a tool returning the shared envelope necessarily contains. */
    private const ENVELOPE_KEYS = ["'total_count' =>", "'page' =>", "'page_size' =>", "'items' =>"];

    /**
     * Tools that build the envelope on one path and something else on another.
     *
     * `list_attribute_sets` returns one attribute set in full — no `items`, no
     * paging — the moment `attribute_set_id` is passed, so the envelope would
     * be a promise it breaks on half its calls. Splitting it into two tools
     * would fix that; until then the omission is the answer, not an oversight.
     */
    private const BRANCHES_TO_ANOTHER_SHAPE = ['list_attribute_sets'];

    /**
     * Every tool registered in di.xml, as name => fully-qualified class.
     *
     * @return array<string, string>
     */
    private function registeredTools(): array
    {
        $di = file_get_contents(__DIR__ . '/../../../../etc/di.xml');
        self::assertIsString($di);

        preg_match_all(
            '#<item name="([a-z0-9_]+)" xsi:type="object">(Magenx\\\\AiMcp\\\\Model\\\\Tool\\\\[A-Za-z\\\\]+)</item>#',
            $di,
            $matches,
            PREG_SET_ORDER
        );
        self::assertNotEmpty($matches, 'No tools found in di.xml — the pattern has drifted.');

        $tools = [];
        foreach ($matches as $match) {
            $tools[$match[1]] = $match[2];
        }

        return $tools;
    }

    /**
     * The tool's own source and that of every ancestor up to AbstractTool.
     *
     * A subclass can inherit both its `execute()` and its schema from a shared
     * base — three of them do — so looking only at the tool's own file would
     * report thirteen tools as promising nothing when the base promises for
     * them.
     *
     * @param string $class
     * @return string
     */
    private function sourceOf(string $class): string
    {
        $source = '';

        $reflection = new ReflectionClass($class);
        while ($reflection !== false) {
            if ($reflection->getName() === AbstractTool::class) {
                break;
            }
            $file = $reflection->getFileName();
            if ($file !== false) {
                $source .= (string) file_get_contents($file);
            }
            $reflection = $reflection->getParentClass();
        }

        return $source;
    }

    /**
     * @param string $source
     * @return bool
     */
    private function buildsTheEnvelope(string $source): bool
    {
        foreach (self::ENVELOPE_KEYS as $key) {
            if (!str_contains($source, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return void
     */
    public function testEveryToolReturningTheEnvelopeDeclaresIt(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (in_array($name, self::BRANCHES_TO_ANOTHER_SHAPE, true)) {
                continue;
            }
            $source = $this->sourceOf($class);
            if (!$this->buildsTheEnvelope($source)) {
                continue;
            }
            if ($this->schemaOf($class) === []) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These return the shared search envelope but promise nothing about it. Override '
            . 'getOutputSchema() to return $this->searchEnvelopeSchema(), or add the tool to '
            . 'BRANCHES_TO_ANOTHER_SHAPE with the reason it cannot.'
        );
    }

    /**
     * The direction that matters more: a promise nothing keeps.
     *
     * @return void
     */
    public function testEveryToolDeclaringTheEnvelopeActuallyReturnsIt(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            if ($this->schemaOf($class) === []) {
                continue;
            }
            if (!$this->buildsTheEnvelope($this->sourceOf($class))) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These advertise the search envelope but do not build its four keys. A declared '
            . 'outputSchema is enforced against structuredContent, so this makes a valid '
            . 'response invalid.'
        );
    }

    /**
     * No write tool may promise an output shape.
     *
     * The confirm gate answers an unconfirmed call with a preview — a different
     * object entirely, through the same result helper — and that is the normal
     * response rather than the exception. Any schema a write tool declared
     * would be violated by it.
     *
     * @return void
     */
    public function testNoWriteToolDeclaresAnOutputSchema(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            if ($tool->isWrite() && $this->schemaOf($class) !== []) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A write tool answers an unconfirmed call with the confirm preview, which no schema '
            . 'for its applied result describes.'
        );
    }

    /**
     * @return void
     */
    public function testEveryDeclaredSchemaIsWellFormed(): void
    {
        $checked = 0;
        foreach ($this->registeredTools() as $name => $class) {
            $schema = $this->schemaOf($class);
            if ($schema === []) {
                continue;
            }
            $checked++;

            $this->assertSame('object', $schema['type'] ?? null, $name);
            $this->assertNotEmpty($schema['properties'] ?? [], $name);
            // A required key with no matching property is a schema nothing can
            // satisfy, and JSON Schema will not say so — it simply fails.
            $this->assertSame(
                [],
                array_diff($schema['required'] ?? [], array_keys($schema['properties'])),
                $name
            );
        }

        $this->assertGreaterThan(0, $checked, 'No schemas were reached — the check proved nothing.');
    }

    /**
     * @param string $class
     * @return array<string, mixed>
     */
    private function schemaOf(string $class): array
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(StructuredToolInterface::class, $tool);

        return $tool->getOutputSchema();
    }
}
