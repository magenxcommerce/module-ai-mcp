<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

/**
 * Turns the raw tail of a log into entries a model can read.
 *
 * Magento's logs are Monolog line logs, but one record is not one line: an
 * exception is written with its whole stack trace on the lines that follow, and
 * a trace is easily two hundred frames of framework plumbing. Counting lines
 * would let one exception crowd out every other entry, and filtering lines
 * would separate a trace from the message it belongs to. So the tail is grouped
 * into entries first — a new one starts at each `[YYYY-MM-DD` timestamp — and
 * each entry is then cut down: long lines clipped, traces reduced to their
 * first frames, everything redacted.
 */
class LogEntries
{
    /** The prefix Monolog's LineFormatter gives every record Magento writes. */
    private const ENTRY_START = '/^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/';

    /** One frame of a PHP stack trace as Exception::getTraceAsString() prints it. */
    private const FRAME = '/^#(\d+) /';

    /** A line longer than this is clipped; serialized payloads are the usual culprit. */
    public const MAX_LINE_CHARS = 1000;

    /**
     * @param LogRedactor $redactor
     */
    public function __construct(
        private readonly LogRedactor $redactor
    ) {
    }

    /**
     * Group raw lines into entries, oldest first.
     *
     * A log that does not use Monolog's prefix at all — a third-party module's
     * own format — has no entry boundaries to find, so each line becomes an
     * entry of its own rather than the whole tail becoming one.
     *
     * @param array<int, string> $lines
     * @return array<int, array<int, string>>
     */
    public function group(array $lines): array
    {
        $hasBoundaries = false;
        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_START, $line)) {
                $hasBoundaries = true;
                break;
            }
        }
        if (!$hasBoundaries) {
            return array_map(static fn (string $line): array => [$line], array_values($lines));
        }

        $entries = [];
        $current = [];
        foreach ($lines as $line) {
            if ($current !== [] && preg_match(self::ENTRY_START, $line)) {
                $entries[] = $current;
                $current = [];
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * An entry's whole text, redacted but not shortened, for filtering.
     *
     * Matching against what the client is shown rather than what is on disk
     * keeps a filter from becoming an oracle: otherwise asking for entries
     * containing a given e-mail address would confirm it is in the log while
     * every entry returned showed only "[email]".
     *
     * @param array<int, string> $lines
     * @return string
     */
    public function searchable(array $lines): string
    {
        return $this->redactor->redact($this->validUtf8(implode("\n", $lines)));
    }

    /**
     * One entry made safe and short: frames trimmed, lines clipped, redacted.
     *
     * @param array<int, string> $lines
     * @param int $traceFrames Frames kept from each trace in the entry.
     * @return string
     */
    public function format(array $lines, int $traceFrames): string
    {
        $kept = [];
        $dropped = 0;
        $frameIndex = 0;

        foreach ($lines as $line) {
            if (preg_match(self::FRAME, $line, $match)) {
                // A chained exception starts its own trace again at #0, and
                // each trace deserves its first frames rather than sharing one
                // allowance with the trace before it.
                if ($match[1] === '0') {
                    $this->flushDropped($kept, $dropped);
                    $frameIndex = 0;
                }
                if ($frameIndex++ >= $traceFrames) {
                    $dropped++;
                    continue;
                }
            } else {
                $this->flushDropped($kept, $dropped);
            }
            $kept[] = $this->clip($this->redactor->redact($this->validUtf8($line)));
        }
        $this->flushDropped($kept, $dropped);

        return implode("\n", $kept);
    }

    /**
     * Note how many frames were left out, where they were left out.
     *
     * @param array<int, string> $kept
     * @param int $dropped
     * @return void
     */
    private function flushDropped(array &$kept, int &$dropped): void
    {
        if ($dropped > 0) {
            $kept[] = sprintf('#… %d more frame%s omitted', $dropped, $dropped === 1 ? '' : 's');
            $dropped = 0;
        }
    }

    /**
     * Replace bytes that are not UTF-8.
     *
     * Logs hold whatever a module wrote, binary payloads included, and the JSON
     * serializer the server answers with refuses malformed UTF-8 outright: one
     * bad byte would turn the whole response into an error.
     *
     * @param string $line
     * @return string
     */
    private function validUtf8(string $line): string
    {
        return mb_check_encoding($line, 'UTF-8') ? $line : mb_convert_encoding($line, 'UTF-8', 'UTF-8');
    }

    /**
     * Clipped after redacting rather than before, so a cut can never leave
     * half an e-mail address or token that no pattern recognises any more.
     *
     * @param string $line
     * @return string
     */
    private function clip(string $line): string
    {
        $length = mb_strlen($line);
        if ($length <= self::MAX_LINE_CHARS) {
            return $line;
        }

        return mb_substr($line, 0, self::MAX_LINE_CHARS)
            . sprintf(' … [%d more characters]', $length - self::MAX_LINE_CHARS);
    }
}
