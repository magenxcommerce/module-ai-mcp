<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Name the logs and error reports the other log tools can read.
 *
 * `read_log` takes a file name and refuses any it did not list here, so this is
 * the way to find out what a store actually has: a stock install writes
 * `system.log`, `exception.log` and `debug.log`, but modules add their own and
 * logrotate adds numbered siblings.
 */
class ListLogs extends AbstractTool
{
    private const DEFAULT_REPORTS = 10;
    private const MAX_REPORTS = 50;

    /**
     * @param LogFiles $logFiles
     */
    public function __construct(
        private readonly LogFiles $logFiles
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_logs';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Magento log files in var/log that read_log can read (newest first, with size '
            . 'and last-modified time), plus the most recent error reports in var/report that '
            . 'read_error_report can read. Start here when diagnosing a fault: exception.log and '
            . 'system.log are the usual places, and a storefront "There has been an error processing '
            . 'your request" page names the report id to look up.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reports' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_REPORTS,
                    'description' => 'How many of the most recent error reports to list. Defaults to '
                        . self::DEFAULT_REPORTS . ', at most ' . self::MAX_REPORTS . '; 0 lists none.',
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
        return 'Magenx_AiMcp::logs';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $limit = min(
            max(0, $this->optionalInt($arguments, 'reports') ?? self::DEFAULT_REPORTS),
            self::MAX_REPORTS
        );
        $reports = $this->logFiles->listReports($limit);

        return [
            'logs' => $this->logFiles->listLogs(),
            'reports' => $reports['recent'],
            'report_count' => $reports['total'],
            // Past the scan cap, "most recent" means most recent of those looked at.
            'reports_partially_scanned' => $reports['scanned'] < $reports['total'],
        ];
    }
}
