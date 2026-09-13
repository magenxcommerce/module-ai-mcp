<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * Read this server's own audit log.
 *
 * The log records every attempted write with the integration that made it, plus
 * refused tokens and blocked addresses. Nothing here can erase or alter it: the
 * file name is fixed and there is no argument that redirects the read, because
 * a log an agent can point somewhere else is a log that can be used to read any
 * file the web server can.
 */
class ReadAuditLog extends AbstractTool
{
    /** Matches the handler's fileName in di.xml, relative to var/log. */
    private const FILE = 'magenx_ai_mcp.log';

    private const DEFAULT_LINES = 100;
    private const MAX_LINES = 1000;

    /** Most of the tail to read off disk, so a log that has grown is still bounded. */
    private const MAX_TAIL_BYTES = 524288;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'read_audit_log';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the end of this server\'s audit log — every write attempted through it, with '
            . 'the integration that made it, plus refused tokens and blocked addresses. Reads are '
            . 'not logged, so this answers "what has been changed through here", not "what has '
            . 'been looked at". Only this one file can be read; there is no path argument.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LINES,
                    'description' => 'How many of the most recent lines to return. Defaults to '
                        . self::DEFAULT_LINES . ', at most ' . self::MAX_LINES . '.',
                ],
                'contains' => [
                    'type' => 'string',
                    'description' => 'Keep only lines containing this, e.g. a tool name or an '
                        . 'integration label. Case-insensitive, applied after the tail is read.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_AiMcp::ops';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $wanted = min(max(1, $this->optionalInt($arguments, 'lines') ?? self::DEFAULT_LINES), self::MAX_LINES);
        $contains = $this->optionalString($arguments, 'contains');

        $log = $this->filesystem->getDirectoryRead(DirectoryList::LOG);
        if (!$log->isExist(self::FILE)) {
            // An absent file is the ordinary state of a server that has not been
            // written through yet, not an error.
            return [
                'file' => self::FILE,
                'exists' => false,
                'lines' => [],
                'note' => 'No audit log has been written yet: nothing has been changed through this server.',
            ];
        }

        $size = (int) ($log->stat(self::FILE)['size'] ?? 0);
        $lines = $this->tail($log, $size);
        if ($contains !== null) {
            $needle = strtolower($contains);
            $lines = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_contains(strtolower($line), $needle)
            ));
        }

        return [
            'file' => self::FILE,
            'exists' => true,
            'size_bytes' => $size,
            // True when there is more log than was returned, either because the
            // file is longer than the tail read or because it holds more lines
            // than were asked for.
            'truncated' => $size > self::MAX_TAIL_BYTES || count($lines) > $wanted,
            'lines' => array_slice($lines, -$wanted),
        ];
    }

    /**
     * The last chunk of the file, split into lines.
     *
     * @param ReadInterface $log
     * @param int $size
     * @return array<int, string>
     */
    private function tail(ReadInterface $log, int $size): array
    {
        $offset = max(0, $size - self::MAX_TAIL_BYTES);

        $file = $log->openFile(self::FILE);
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
}
