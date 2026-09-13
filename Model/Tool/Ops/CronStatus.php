<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Cron\Model\Schedule;

/**
 * Report whether cron is running, and what it has been doing.
 *
 * Several tools in this server hand work to cron rather than doing it:
 * invalidate_indexers marks indexers for a rebuild that only cron performs, and
 * a store whose cron has stopped is a store where those tools report success and
 * nothing changes. This is how to tell.
 */
class CronStatus extends AbstractTool
{
    /** How many recent runs to summarise. */
    private const WINDOW = 200;

    /** How many failures to name, out of that window. */
    private const MAX_FAILURES = 20;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cron_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Report whether Magento cron is running: how many jobs are pending, running, '
            . 'succeeded, missed or errored in the recent schedule, when one last completed, and '
            . 'the jobs that failed with their messages. A store with no successful run in hours '
            . 'is a store where invalidate_indexers and anything else queued for cron will report '
            . 'success and change nothing.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job_code' => [
                    'type' => 'string',
                    'description' => 'Restrict to one job, e.g. "indexer_reindex_all_invalid". '
                        . 'Exact match.',
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
        $collection = $this->collectionFactory->create();
        $jobCode = $this->optionalString($arguments, 'job_code');
        if ($jobCode !== null) {
            $collection->addFieldToFilter('job_code', $jobCode);
        }
        $collection->setOrder('scheduled_at', 'DESC');
        $collection->setPageSize(self::WINDOW);

        $counts = [];
        $failures = [];
        $lastSuccessAt = null;
        $rows = 0;

        foreach ($collection as $schedule) {
            /** @var Schedule $schedule */
            $rows++;
            $status = (string) $schedule->getStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            if ($status === Schedule::STATUS_SUCCESS && $lastSuccessAt === null) {
                // Ordered newest first, so the first one seen is the latest.
                $lastSuccessAt = $schedule->getFinishedAt() ?? $schedule->getExecutedAt();
            }

            $failed = $status === Schedule::STATUS_ERROR || $status === Schedule::STATUS_MISSED;
            if ($failed && count($failures) < self::MAX_FAILURES) {
                $failures[] = [
                    'job_code' => $schedule->getJobCode(),
                    'status' => $status,
                    'scheduled_at' => $schedule->getScheduledAt(),
                    'executed_at' => $schedule->getExecutedAt(),
                    'messages' => $schedule->getMessages(),
                ];
            }
        }

        return [
            'window' => self::WINDOW,
            'rows_examined' => $rows,
            'counts_by_status' => $counts,
            'last_success_at' => $lastSuccessAt,
            // An empty schedule usually means cron has never run at all, rather
            // than that every job succeeded.
            'schedule_is_empty' => $rows === 0,
            'failures' => $failures,
        ];
    }
}
