<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read the end of one Magento log in var/log.
 *
 * Read-only, and bounded in every direction a log is unbounded: only the last
 * megabyte is read off disk, only the newest entries are returned, long lines
 * are clipped, stack traces keep their first frames, and the whole answer has a
 * size budget. Every entry is redacted on the way out — see {@see LogRedactor}
 * for what that does and does not catch.
 *
 * The file is chosen by name from the listing {@see LogFiles} takes itself;
 * there is no path argument.
 */
class ReadLog extends AbstractTool
{
    private const DEFAULT_ENTRIES = 50;
    private const MAX_ENTRIES = 500;

    private const DEFAULT_TRACE_FRAMES = 5;
    private const MAX_TRACE_FRAMES = 50;

    /** The most text one answer carries, whatever was asked for. */
    private const MAX_OUTPUT_CHARS = 200000;

    /**
     * @param LogFiles $logFiles
     * @param LogEntries $logEntries
     */
    public function __construct(
        private readonly LogFiles $logFiles,
        private readonly LogEntries $logEntries
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'read_log';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the most recent entries of one Magento log in var/log, e.g. exception.log or '
            . 'system.log. An entry is one log record with any stack trace that follows it, so a '
            . 'long exception counts once. Only the last 1 MB of the file is read; long lines are '
            . 'clipped, traces keep their first frames, and e-mail addresses, tokens, passwords, '
            . 'card numbers and the host part of IP addresses are masked (best effort: free-text '
            . 'personal data such as names or street addresses can remain). Call list_logs for '
            . 'the file names; there is no path argument.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file' => [
                    'type' => 'string',
                    'description' => 'A log file name exactly as list_logs returns it, e.g. "exception.log".',
                ],
                'entries' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_ENTRIES,
                    'description' => 'How many of the most recent entries to return. Defaults to '
                        . self::DEFAULT_ENTRIES . ', at most ' . self::MAX_ENTRIES . '.',
                ],
                'contains' => [
                    'type' => 'string',
                    'description' => 'Keep only entries containing this, e.g. "CRITICAL", an order '
                        . 'number or a class name. Case-insensitive, matched against the whole '
                        . 'entry including its full trace, after masking: a masked e-mail address '
                        . 'cannot be searched for.',
                ],
                'trace_frames' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_TRACE_FRAMES,
                    'description' => 'Stack frames kept from each trace. Defaults to '
                        . self::DEFAULT_TRACE_FRAMES . ', at most ' . self::MAX_TRACE_FRAMES . '.',
                ],
            ],
            'required' => ['file'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_AiMcp::logs';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $file = $this->requireString($arguments, 'file');
        $wanted = min(
            max(1, $this->optionalInt($arguments, 'entries') ?? self::DEFAULT_ENTRIES),
            self::MAX_ENTRIES
        );
        $frames = min(
            max(0, $this->optionalInt($arguments, 'trace_frames') ?? self::DEFAULT_TRACE_FRAMES),
            self::MAX_TRACE_FRAMES
        );
        $contains = $this->optionalString($arguments, 'contains');

        $tail = $this->logFiles->tail($file);
        $entries = $this->logEntries->group($tail['lines']);
        if ($contains !== null) {
            $needle = mb_strtolower($contains);
            $entries = array_values(array_filter(
                $entries,
                fn (array $lines): bool => str_contains(
                    mb_strtolower($this->logEntries->searchable($lines)),
                    $needle
                )
            ));
        }
        $matched = count($entries);

        // Newest first while spending the budget, so it is the oldest that are
        // dropped when it runs out; then back to file order for reading.
        $returned = [];
        $spent = 0;
        foreach (array_reverse(array_slice($entries, -$wanted)) as $lines) {
            $text = $this->logEntries->format($lines, $frames);
            $spent += mb_strlen($text);
            if ($returned !== [] && $spent > self::MAX_OUTPUT_CHARS) {
                break;
            }
            $returned[] = $text;
        }
        $returned = array_reverse($returned);

        return [
            'file' => $tail['file'],
            'size_bytes' => $tail['size_bytes'],
            // The file is longer than the part read, so older entries exist
            // that neither this answer nor a larger "entries" can reach.
            'tail_only' => $tail['partial'],
            'matched' => $matched,
            'returned' => count($returned),
            'entries' => $returned,
        ];
    }
}
