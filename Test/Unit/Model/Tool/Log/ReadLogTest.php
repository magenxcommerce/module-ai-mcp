<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\Log\ListLogs;
use Magenx\AiMcp\Model\Tool\Log\LogEntries;
use Magenx\AiMcp\Model\Tool\Log\LogFiles;
use Magenx\AiMcp\Model\Tool\Log\LogRedactor;
use Magenx\AiMcp\Model\Tool\Log\ReadLog;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Listing and reading var/log.
 *
 * The property everything else rests on is that the request names a log and
 * never a path: a name is honoured only when it is one the listing produced.
 *
 * @see ReadLog
 * @see ListLogs
 * @see LogFiles
 */
class ReadLogTest extends TestCase
{
    use FakeDirectory;

    private const FILES = [
        'log/system.log' => "[2026-10-06T10:00:00+00:00] main.INFO: one\n"
            . "[2026-10-06T10:00:01+00:00] main.INFO: two jane@example.com\n"
            . "[2026-10-06T10:00:02+00:00] main.INFO: three\n",
        'log/exception.log' => "[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom\n[stacktrace]\n"
            . "#0 a\n#1 b\n#2 c\n#3 d\n#4 e\n#5 f\n#6 g\n",
        'log/system.log.1' => "old\n",
        'log/magenx_ai_mcp.log' => "audit\n",
        'log/notes.txt' => "not a log\n",
        'log/debug/nested.log' => "nested\n",
        'env.php' => "<?php return ['db' => 'secret'];\n",
        'report/1234567890' => '{"0":"Boom","1":"#0 a\n#1 b","url":"/checkout?token=abc","script_name":"/index.php"}',
        'report/README' => "not a report\n",
    ];

    /**
     * @return ReadLog
     */
    private function tool(): ReadLog
    {
        return new ReadLog(
            new LogFiles($this->filesystemHolding(self::FILES)),
            new LogEntries(new LogRedactor())
        );
    }

    /**
     * @return void
     */
    public function testTheToolsAreReadsBehindTheLogsResource(): void
    {
        $files = new LogFiles($this->filesystemHolding([]));
        foreach ([$this->tool(), new ListLogs($files)] as $tool) {
            $this->assertFalse($tool->isWrite());
            $this->assertSame('Magenx_AiMcp::logs', $tool->getAclResource());
        }
    }

    /**
     * Top-level .log files and their rotations; not the audit log, not other
     * extensions, not subdirectories.
     *
     * @return void
     */
    public function testOnlyLogFilesAreListed(): void
    {
        $result = (new ListLogs(new LogFiles($this->filesystemHolding(self::FILES))))->execute([]);

        $names = array_column($result['logs'], 'file');
        sort($names);
        $this->assertSame(['exception.log', 'system.log', 'system.log.1'], $names);
        $this->assertSame(['1234567890'], array_column($result['reports'], 'report_id'));
        $this->assertSame(1, $result['report_count']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unlistedNames(): array
    {
        return [
            'parent traversal' => ['../env.php'],
            'traversal ending in .log' => ['../../app/etc/env.php/../x.log'],
            'absolute path' => ['/etc/passwd'],
            'nested log' => ['debug/nested.log'],
            'audit log' => ['magenx_ai_mcp.log'],
            'other extension' => ['notes.txt'],
            'missing log' => ['nope.log'],
        ];
    }

    /**
     * @param string $file
     * @return void
     */
    #[DataProvider('unlistedNames')]
    public function testANameTheListingDidNotProduceIsRefused(string $file): void
    {
        $this->expectException(LocalizedException::class);

        $this->tool()->execute(['file' => $file]);
    }

    /**
     * @return void
     */
    public function testTheNewestEntriesAreReturnedRedacted(): void
    {
        $result = $this->tool()->execute(['file' => 'system.log', 'entries' => 2]);

        $this->assertSame(3, $result['matched']);
        $this->assertSame([
            '[2026-10-06T10:00:01+00:00] main.INFO: two [email]',
            '[2026-10-06T10:00:02+00:00] main.INFO: three',
        ], $result['entries']);
        $this->assertFalse($result['tail_only']);
    }

    /**
     * @return void
     */
    public function testTheEntryCountIsCapped(): void
    {
        $result = $this->tool()->execute(['file' => 'system.log', 'entries' => 99999]);

        $this->assertSame(3, $result['returned']);
    }

    /**
     * @return void
     */
    public function testAFilterCannotFindWhatTheOutputMasks(): void
    {
        $this->assertSame(0, $this->tool()->execute(['file' => 'system.log', 'contains' => 'jane@'])['matched']);
        $this->assertSame(1, $this->tool()->execute(['file' => 'system.log', 'contains' => 'TWO'])['matched']);
    }

    /**
     * @return void
     */
    public function testTracesAreCutToTheirFirstFrames(): void
    {
        $result = $this->tool()->execute(['file' => 'exception.log', 'trace_frames' => 2]);

        $this->assertSame([
            "[2026-10-06T10:00:00+00:00] main.CRITICAL: Boom\n[stacktrace]\n#0 a\n#1 b\n#… 5 more frames omitted",
        ], $result['entries']);
    }

    /**
     * @return void
     */
    public function testOnlyTheTailOfALargeLogIsRead(): void
    {
        $line = "[2026-10-06T10:00:00+00:00] main.INFO: filler\n";
        $big = str_repeat($line, (int) ceil(LogFiles::MAX_TAIL_BYTES / strlen($line)) + 10)
            . "[2026-10-06T10:00:09+00:00] main.INFO: last\n";
        $tool = new ReadLog(
            new LogFiles($this->filesystemHolding(['log/system.log' => $big])),
            new LogEntries(new LogRedactor())
        );

        $result = $tool->execute(['file' => 'system.log', 'entries' => 1]);

        $this->assertTrue($result['tail_only']);
        $this->assertSame(['[2026-10-06T10:00:09+00:00] main.INFO: last'], $result['entries']);
    }
}
