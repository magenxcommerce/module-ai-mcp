<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Platform;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Platform\Model\CollectorPool;
use Magenx\Platform\Model\CollectorRunner;
use Magenx\Platform\Model\Config;
use Magenx\Platform\Model\Metric\Status;
use Magento\Framework\Exception\LocalizedException;

/**
 * Live health of the services behind this store.
 *
 * Every other diagnostic in this server describes Magento — caches, indexers,
 * cron, modules. This one describes what Magento is standing on, which is where
 * the cause usually is when all of those look fine and the store is still slow.
 *
 * The probing, the failure isolation and the rate limiting all belong to
 * CollectorRunner: a backend that cannot be reached comes back as one
 * "unavailable" entry with a reason, never as a failed tool call, and repeated
 * calls inside the module's cache TTL are answered from its snapshot rather
 * than re-probing. That matters more here than in the admin, because an agent
 * in a loop can call a tool far faster than anyone can click Refresh.
 */
class PlatformStatus extends AbstractTool
{
    /**
     * @param CollectorPool $pool
     * @param CollectorRunner $runner
     * @param Config $config
     */
    public function __construct(
        private readonly CollectorPool $pool,
        private readonly CollectorRunner $runner,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'platform_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read live health and metrics for the services behind this store — MariaDB, Redis, '
            . 'RabbitMQ, OpenSearch, PHP-FPM and Nginx — as status, a one-line summary and rows of '
            . 'measured values. Use this when the store is slow or erroring and cache_status, '
            . 'indexer_status and cron_status all look healthy. A backend that cannot be reached '
            . 'is reported as "unavailable" with the reason rather than failing the call.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collectors' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Which backends to probe, by code. Omit for every one the '
                        . 'store has enabled. Probing is sequential, so naming the one you care '
                        . 'about is faster than reading them all.',
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
        return 'Magenx_Platform::platform';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__(
                'Platform Overview is switched off. An administrator can enable it under '
                . 'Stores > Configuration > Magenx > Platform.'
            ));
        }

        $enabled = $this->config->getEnabledCollectors();
        $requested = $this->requestedCodes($arguments, $enabled);

        $results = [];
        foreach ($requested as $code) {
            $collector = $this->pool->get($code);
            // Enabled in configuration but absent from the pool: the collector
            // was removed while its code stayed in the stored list. Reported in
            // the shape a failed probe uses, so one stale setting does not cost
            // the whole reading.
            $results[] = $collector === null
                ? ['code' => $code] + $this->runner->unavailable('No collector is registered under this code.')
                : $this->runner->run($code, $collector);
        }

        return [
            'enabled_collectors' => array_values($enabled),
            'items' => $results,
            // Naming the vocabulary beats leaving an agent to infer it from
            // whichever statuses happen to be present in one reading.
            'status_values' => [Status::INFO, Status::OK, Status::WARN, Status::UNAVAILABLE, Status::ERROR],
        ];
    }

    /**
     * The collector codes to probe, refusing anything the store has not enabled.
     *
     * @param array<string, mixed> $arguments
     * @param string[] $enabled
     * @return string[]
     * @throws LocalizedException
     */
    private function requestedCodes(array $arguments, array $enabled): array
    {
        $requested = $this->optionalArray($arguments, 'collectors');
        if ($requested === []) {
            return array_values($enabled);
        }

        $codes = [];
        foreach ($requested as $code) {
            if (!is_string($code) || trim($code) === '') {
                throw new LocalizedException(__('Every entry in "collectors" must be a code string.'));
            }
            $code = trim($code);
            if (!in_array($code, $enabled, true)) {
                throw new LocalizedException(__(
                    'Unknown or disabled collector "%1". This store has enabled: %2.',
                    $code,
                    $enabled === [] ? '(none)' : implode(', ', $enabled)
                ));
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }
}
