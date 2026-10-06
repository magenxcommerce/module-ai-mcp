<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * The only files the log tools can read, and the bounded way they read them.
 *
 * A file is never named by the request. The request names one, and that name
 * is looked up in a listing this class takes of `var/log` itself; what is read
 * is the listed entry, so a `../`, an absolute path or an encoded separator has
 * nothing to match and is refused as an unknown log rather than normalised into
 * something plausible. Error reports are the one exception and need no listing:
 * their id is digits and nothing else, which cannot spell a path.
 *
 * This server's own audit log is deliberately left out. `read_audit_log` reads
 * it behind `Magenx_AiMcp::ops`, and seeing which integration changed what is a
 * decision about that grant, not a side effect of this one.
 */
class LogFiles
{
    /** A log file name: the stock `.log` files and their rotated `.log.1` siblings. */
    private const LOG_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.log(?:\.\d{1,3})?$/';

    /** Kept out of the listing; see the class comment. */
    private const AUDIT_LOG = 'magenx_ai_mcp.log';

    /** Where Magento's error processor writes reports, relative to var/. */
    public const REPORT_DIR = 'report';

    /** A report id as Magento generates it: a run of digits. */
    public const REPORT_ID = '/^\d{1,32}$/';

    /** Most of the tail to read off disk, so a log of any size costs the same. */
    public const MAX_TAIL_BYTES = 1048576;

    /** A report is one exception; anything larger than this is not worth reading whole. */
    public const MAX_REPORT_BYTES = 262144;

    /** Reports stat'ed when listing, so a directory nobody has cleaned in years stays cheap. */
    private const MAX_REPORTS_SCANNED = 5000;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Every readable log, newest first.
     *
     * @return array<int, array{file: string, size_bytes: int, modified_at: string|null}>
     */
    public function listLogs(): array
    {
        $directory = $this->logDirectory();
        if (!$directory->isExist()) {
            return [];
        }

        $logs = [];
        foreach ($directory->read() as $path) {
            $name = (string) $path;
            if (str_contains($name, '/') || !$this->isReadableLogName($name) || !$directory->isFile($name)) {
                continue;
            }
            $logs[] = ['file' => $name] + $this->describe($directory, $name);
        }

        usort($logs, static fn (array $a, array $b): int => strcmp(
            (string) $b['modified_at'],
            (string) $a['modified_at']
        ));

        return $logs;
    }

    /**
     * The most recent error reports, and how many there are in all.
     *
     * @param int $limit
     * @return array{total: int, scanned: int, recent: array<int, array<string, mixed>>}
     */
    public function listReports(int $limit): array
    {
        $var = $this->varDirectory();
        if (!$var->isExist(self::REPORT_DIR)) {
            return ['total' => 0, 'scanned' => 0, 'recent' => []];
        }

        $total = 0;
        $reports = [];
        foreach ($var->read(self::REPORT_DIR) as $path) {
            $id = substr((string) $path, strlen(self::REPORT_DIR . '/'));
            if (!preg_match(self::REPORT_ID, $id)) {
                continue;
            }
            $total++;
            if (count($reports) < self::MAX_REPORTS_SCANNED) {
                $reports[] = ['report_id' => $id] + $this->describe($var, self::REPORT_DIR . '/' . $id);
            }
        }

        usort($reports, static fn (array $a, array $b): int => strcmp(
            (string) $b['modified_at'],
            (string) $a['modified_at']
        ));

        return [
            'total' => $total,
            'scanned' => count($reports),
            'recent' => array_slice($reports, 0, $limit),
        ];
    }

    /**
     * The tail of a listed log, as lines, plus the file's size.
     *
     * @param string $requested The name the client asked for.
     * @return array{file: string, size_bytes: int, lines: array<int, string>, partial: bool}
     * @throws LocalizedException When the name is not one of the listed logs.
     */
    public function tail(string $requested): array
    {
        $listed = null;
        foreach ($this->listLogs() as $log) {
            if ($log['file'] === $requested) {
                // The listing's own string from here on, never the request's.
                $listed = $log['file'];
                break;
            }
        }
        if ($listed === null) {
            throw new LocalizedException(__(
                'There is no readable log named "%1". Call list_logs for the names that can be read.',
                $requested
            ));
        }

        $directory = $this->logDirectory();
        $size = (int) ($directory->stat($listed)['size'] ?? 0);

        return [
            'file' => $listed,
            'size_bytes' => $size,
            'lines' => $this->readTail($directory, $listed, $size),
            'partial' => $size > self::MAX_TAIL_BYTES,
        ];
    }

    /**
     * One error report's raw contents.
     *
     * @param string $reportId
     * @return array{report_id: string, size_bytes: int, contents: string, partial: bool}
     * @throws LocalizedException
     */
    public function readReport(string $reportId): array
    {
        if (!preg_match(self::REPORT_ID, $reportId)) {
            throw new LocalizedException(__('The "report_id" argument must be the digits of a report id.'));
        }

        $var = $this->varDirectory();
        $path = self::REPORT_DIR . '/' . $reportId;
        if (!$var->isFile($path)) {
            throw new LocalizedException(__('There is no error report %1.', $reportId));
        }

        $size = (int) ($var->stat($path)['size'] ?? 0);
        $file = $var->openFile($path);
        try {
            $contents = (string) $file->read(self::MAX_REPORT_BYTES);
        } finally {
            $file->close();
        }

        return [
            'report_id' => $reportId,
            'size_bytes' => $size,
            'contents' => $contents,
            'partial' => $size > self::MAX_REPORT_BYTES,
        ];
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isReadableLogName(string $name): bool
    {
        return preg_match(self::LOG_NAME, $name) === 1 && !str_starts_with($name, self::AUDIT_LOG);
    }

    /**
     * @param ReadInterface $directory
     * @param string $path
     * @return array{size_bytes: int, modified_at: string|null}
     */
    private function describe(ReadInterface $directory, string $path): array
    {
        $stat = $directory->stat($path);
        $mtime = isset($stat['mtime']) ? (int) $stat['mtime'] : null;

        return [
            'size_bytes' => (int) ($stat['size'] ?? 0),
            'modified_at' => $mtime === null ? null : gmdate('Y-m-d\TH:i:s\Z', $mtime),
        ];
    }

    /**
     * The last chunk of a file, split into lines.
     *
     * @param ReadInterface $directory
     * @param string $name
     * @param int $size
     * @return array<int, string>
     */
    private function readTail(ReadInterface $directory, string $name, int $size): array
    {
        $offset = max(0, $size - self::MAX_TAIL_BYTES);

        $file = $directory->openFile($name);
        try {
            if ($offset > 0) {
                $file->seek($offset);
                // The first line after an arbitrary offset is half a line.
                $file->readLine(self::MAX_TAIL_BYTES);
            }

            $contents = '';
            while (!$file->eof()) {
                $chunk = $file->read(8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $contents .= $chunk;
            }
        } finally {
            $file->close();
        }

        return array_values(array_filter(
            array_map('rtrim', explode("\n", $contents)),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * @return ReadInterface
     */
    private function logDirectory(): ReadInterface
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::LOG);
    }

    /**
     * @return ReadInterface
     */
    private function varDirectory(): ReadInterface
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
    }
}
