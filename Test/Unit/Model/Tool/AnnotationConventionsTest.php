<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The annotation conventions, checked against every registered tool at once.
 *
 * `AbstractTool` and the README both make claims about what the hints say — that
 * a `set_` or `update_` tool is idempotent, that a tool which only ever adds is
 * not destructive — and those claims went stale once already, across four
 * phases, because each is a per-file override that nothing checked. A tool added
 * next year will not read this docblock; this test is what tells it.
 *
 * Read from `etc/di.xml` rather than a list kept here, so a new tool is covered
 * the moment it is registered and there is no second inventory to fall behind.
 * The classes are reflected, never constructed: every value under test is a
 * literal return, and building one would need the whole Magento object graph.
 *
 * The exceptions are named individually below, because each is a decision worth
 * being asked about rather than a gap to fill.
 */
class AnnotationConventionsTest extends TestCase
{
    /**
     * Write tools named `create_` or `add_` that are destructive anyway.
     *
     * The first three move money or goods and cannot be taken back. The fourth
     * assigns image roles, and giving a role to one image takes it from
     * whichever image held it — additive in name only.
     */
    private const DESTRUCTIVE_ADDITIONS = [
        'create_invoice',
        'create_shipment',
        'create_credit_memo',
        'add_product_media',
    ];

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
     * Reads the tool's advertised hints without constructing it.
     *
     * @param string $class
     * @return array<string, bool>
     */
    private function annotationsOf(string $class): array
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(AbstractTool::class, $tool);

        return $tool->getAnnotations();
    }

    /**
     * @return void
     */
    public function testEverySetOrUpdateToolAdvertisesItselfAsIdempotent(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (!str_starts_with($name, 'set_') && !str_starts_with($name, 'update_')) {
                continue;
            }
            if ($this->annotationsOf($class)['idempotentHint'] !== true) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These write the fields named to the values given, so a repeat lands in the same place: '
            . 'override isIdempotent() to return true, or rename the tool if it is not one of those.'
        );
    }

    /**
     * @return void
     */
    public function testEveryCreateOrAddToolIsAdditiveUnlessItIsOneOfTheNamedFour(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (!str_starts_with($name, 'create_') && !str_starts_with($name, 'add_')) {
                continue;
            }
            $destructive = $this->annotationsOf($class)['destructiveHint'];
            $expected = in_array($name, self::DESTRUCTIVE_ADDITIONS, true);
            if ($destructive !== $expected) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A tool that only ever adds should override isDestructive() to return false. If this one '
            . 'replaces or removes something despite its name, add it to DESTRUCTIVE_ADDITIONS with '
            . 'the reason.'
        );
    }

    /**
     * A delete that claimed to be non-destructive would invert the one hint a
     * client is most likely to act on.
     *
     * @return void
     */
    public function testNoDeleteToolClaimsToBeNonDestructive(): void
    {
        $offenders = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (!str_starts_with($name, 'delete_') && !str_starts_with($name, 'remove_')) {
                continue;
            }
            if ($this->annotationsOf($class)['destructiveHint'] !== true) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * readOnlyHint is derived rather than overridden, and MCP defines the other
     * two only when it is false. A read tool advertising them would be telling
     * a client something the spec says is undefined.
     *
     * @return void
     */
    public function testReadToolsAdvertiseNeitherWriteHint(): void
    {
        $checked = 0;
        foreach ($this->registeredTools() as $name => $class) {
            $annotations = $this->annotationsOf($class);
            if ($annotations['readOnlyHint'] !== true) {
                continue;
            }
            $checked++;
            $this->assertArrayNotHasKey('destructiveHint', $annotations, $name);
            $this->assertArrayNotHasKey('idempotentHint', $annotations, $name);
            $this->assertFalse($annotations['openWorldHint'], $name);
        }

        $this->assertGreaterThan(0, $checked, 'No read tools were reached — the check proved nothing.');
    }
}
