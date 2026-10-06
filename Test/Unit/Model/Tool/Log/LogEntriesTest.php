<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\Log\LogEntries;
use Magenx\AiMcp\Model\Tool\Log\LogRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Grouping a log tail into entries and cutting each one down.
 *
 * @see LogEntries
 */
class LogEntriesTest extends TestCase
{
    private LogEntries $entries;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->entries = new LogEntries(new LogRedactor());
    }

    /**
     * A trace belongs to the record above it, not to an entry of its own.
     *
     * @return void
     */
    public function testATraceStaysWithItsRecord(): void
    {
        $grouped = $this->entries->group([
            '#4 half of an entry the tail cut into',
            '[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom',
            '[stacktrace]',
            '#0 /a.php(1): f()',
            '[2026-10-06T10:00:01+00:00] main.INFO: Fine',
        ]);

        $this->assertSame([
            ['#4 half of an entry the tail cut into'],
            ['[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom', '[stacktrace]', '#0 /a.php(1): f()'],
            ['[2026-10-06T10:00:01+00:00] main.INFO: Fine'],
        ], $grouped);
    }

    /**
     * @return void
     */
    public function testALogWithoutTimestampsIsOneEntryPerLine(): void
    {
        $this->assertSame([['a'], ['b']], $this->entries->group(['a', 'b']));
    }

    /**
     * Each trace in a chain keeps its own first frames.
     *
     * @return void
     */
    public function testEveryTraceKeepsItsFirstFrames(): void
    {
        $text = $this->entries->format([
            '[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom',
            '#0 a', '#1 b', '#2 c', '#3 d',
            '[previous exception] Inner',
            '#0 e', '#1 f', '#2 g',
        ], 2);

        $this->assertSame(implode("\n", [
            '[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom',
            '#0 a', '#1 b', '#… 2 more frames omitted',
            '[previous exception] Inner',
            '#0 e', '#1 f', '#… 1 more frame omitted',
        ]), $text);
    }

    /**
     * @return void
     */
    public function testLongLinesAreClippedAfterRedaction(): void
    {
        $line = 'customer jane@example.com ' . str_repeat('x', 3000);

        $text = $this->entries->format([$line], 5);

        $this->assertStringStartsWith('customer [email] xxx', $text);
        $this->assertStringEndsWith('more characters]', $text);
        $this->assertLessThan(LogEntries::MAX_LINE_CHARS + 50, mb_strlen($text));
    }

    /**
     * Logs carry binary; one bad byte must not make the answer unserializable.
     *
     * @return void
     */
    public function testInvalidUtf8IsReplaced(): void
    {
        $text = $this->entries->format(["bad \xC3\x28 byte"], 5);

        $this->assertNotFalse(json_encode($text));
    }

    /**
     * @return void
     */
    public function testTheSearchableTextIsMaskedLikeTheOutput(): void
    {
        $this->assertSame('hello [email]', $this->entries->searchable(['hello jane@example.com']));
    }
}
