<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Read one error report from var/report.
 *
 * When Magento fails to render a page it writes the exception to a report file
 * and shows the visitor only its id ("There has been an error processing your
 * request. Exception printed is logged … Error log record number: 1234…"). That
 * id is the argument here, and it is digits only, so it cannot spell a path.
 *
 * Reports in a nested layout (MAGE_ERROR_REPORT_DIR_NESTING_LEVEL above zero)
 * are not looked for; the stock layout keeps every report directly in
 * var/report.
 */
class ReadErrorReport extends AbstractTool
{
    private const DEFAULT_TRACE_FRAMES = 15;
    private const MAX_TRACE_FRAMES = 50;

    /**
     * @param LogFiles $logFiles
     * @param LogEntries $logEntries
     * @param LogRedactor $redactor
     * @param Json $json
     */
    public function __construct(
        private readonly LogFiles $logFiles,
        private readonly LogEntries $logEntries,
        private readonly LogRedactor $redactor,
        private readonly Json $json
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'read_error_report';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one Magento error report from var/report by its id — the "Error log record '
            . 'number" an error page shows, or a report_id from list_logs. Returns the exception '
            . 'message, the URL that failed and the first frames of the stack trace, with the same '
            . 'masking of e-mail addresses, tokens, passwords and card numbers as read_log.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_id' => [
                    'type' => 'string',
                    'pattern' => '^[0-9]{1,32}$',
                    'description' => 'The report id, digits only.',
                ],
                'trace_frames' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_TRACE_FRAMES,
                    'description' => 'Stack frames kept from the trace. Defaults to '
                        . self::DEFAULT_TRACE_FRAMES . ', at most ' . self::MAX_TRACE_FRAMES . '.',
                ],
            ],
            'required' => ['report_id'],
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
        // A model may well send the id as a JSON number; a report id is digits
        // either way, so accept both rather than refusing a correct answer.
        $reportId = is_int($arguments['report_id'] ?? null)
            ? (string) $arguments['report_id']
            : $this->requireString($arguments, 'report_id');
        $frames = min(
            max(0, $this->optionalInt($arguments, 'trace_frames') ?? self::DEFAULT_TRACE_FRAMES),
            self::MAX_TRACE_FRAMES
        );

        $report = $this->logFiles->readReport($reportId);
        $result = [
            'report_id' => $report['report_id'],
            'size_bytes' => $report['size_bytes'],
            'truncated' => $report['partial'],
        ];

        $data = $this->decode($report['contents']);
        if ($data === null) {
            // An older or hand-written report, or one cut off at the size cap:
            // show it as text rather than not at all.
            return $result + ['contents' => $this->text($report['contents'], $frames)];
        }

        return $result + [
            'message' => $this->text((string) ($data[0] ?? ''), $frames),
            'trace' => $this->text((string) ($data[1] ?? ''), $frames),
            'url' => isset($data['url']) ? $this->redactor->redact((string) $data['url']) : null,
            'script_name' => isset($data['script_name']) ? (string) $data['script_name'] : null,
        ];
    }

    /**
     * The report's JSON, or null when it is not a JSON object.
     *
     * @param string $contents
     * @return array<int|string, mixed>|null
     */
    private function decode(string $contents): ?array
    {
        try {
            $data = $this->json->unserialize($contents);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param string $text
     * @param int $frames
     * @return string
     */
    private function text(string $text, int $frames): string
    {
        return $this->logEntries->format(explode("\n", rtrim($text)), $frames);
    }
}
