<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool\Proxy;
use PHPUnit\Framework\TestCase;

/**
 * The registry's one job beyond lookup: hold tools without waking them.
 *
 * Every tool is registered as a `\Proxy`, which defers building that tool and
 * its whole dependency graph until something calls a method on it. The saving
 * is entirely undone by one loop that asks each tool a question — and the
 * obvious question, `getName()`, is exactly the one a registry keyed by name
 * wants to ask. That is why the di.xml key carries the name instead, and why
 * the cost of getting this wrong is invisible: a registry that woke everything
 * would return all the same answers, only slower, on every request.
 *
 * So these tests assert on what was *not* called. {@see Proxy} records it.
 *
 * @see ToolRegistry
 */
class ToolRegistryTest extends TestCase
{
    /**
     * @return void
     */
    public function testAToolIsFiledUnderItsKeyWithoutBeingAsked(): void
    {
        $proxy = new Proxy();
        $registry = new ToolRegistry(['flat_tool' => $proxy]);

        self::assertSame(['flat_tool'], $registry->getNames());
        self::assertFalse($proxy->woken, 'Registration asked the tool a question and built it.');
    }

    /**
     * Reading the inventory is the listing path's first move, and the whole
     * point of doing it by name is that it costs nothing.
     *
     * @return void
     */
    public function testReadingNamesAndClassesNeverWakesATool(): void
    {
        $proxy = new Proxy();
        $registry = new ToolRegistry(['flat_tool' => $proxy]);

        $registry->getNames();
        $registry->getClasses();
        $registry->getClass('flat_tool');

        self::assertFalse($proxy->woken);
    }

    /**
     * @return void
     */
    public function testTheRegisteredClassIsReportedAsItWasRegistered(): void
    {
        $registry = new ToolRegistry(['flat_tool' => new Proxy()]);

        self::assertSame(['flat_tool' => Proxy::class], $registry->getClasses());
        self::assertSame(Proxy::class, $registry->getClass('flat_tool'));
        self::assertNull($registry->getClass('no_such_tool'));
    }

    /**
     * A tool contributed by position rather than by name still has to work —
     * an older module, or a test — and the only place its name can come from
     * is the tool. That one is built early, which is the documented cost.
     *
     * @return void
     */
    public function testAPositionallyKeyedToolFallsBackToAskingItsName(): void
    {
        $proxy = new Proxy();
        $registry = new ToolRegistry([$proxy]);

        self::assertSame(['flat_tool'], $registry->getNames());
        self::assertTrue($proxy->woken, 'Nothing else could have supplied the name.');
    }

    /**
     * @return void
     */
    public function testLookupReturnsTheToolAndNullForAnythingElse(): void
    {
        $tool = new FlatTool();
        $registry = new ToolRegistry(['flat_tool' => $tool]);

        self::assertSame($tool, $registry->get('flat_tool'));
        self::assertNull($registry->get('no_such_tool'));
    }

    /**
     * The server lists in registry order, so it is worth being deterministic
     * rather than however di.xml merging happened to order the file.
     *
     * @return void
     */
    public function testToolsAreSortedByName(): void
    {
        $registry = new ToolRegistry([
            'zzz_tool' => new FlatTool(),
            'aaa_tool' => new FlatTool(),
            'mmm_tool' => new FlatTool(),
        ]);

        self::assertSame(['aaa_tool', 'mmm_tool', 'zzz_tool'], $registry->getNames());
    }

    /**
     * A `tools` argument is merged across modules, so a contribution that is
     * not a tool at all is a configuration mistake somewhere else. Skipping it
     * keeps that mistake from taking down every other tool on the server.
     *
     * @return void
     */
    public function testSomethingThatIsNotAToolIsSkipped(): void
    {
        $registry = new ToolRegistry(['flat_tool' => new FlatTool(), 'nonsense' => new \stdClass()]);

        self::assertSame(['flat_tool'], $registry->getNames());
    }
}
