<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Config;

use Magenx\AiMcp\Model\ConfigPathPolicy;
use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * Read store configuration by path or path prefix.
 *
 * Values that look like credentials are redacted rather than returned — the
 * agent still learns the setting exists and is populated, which is all it needs
 * to reason about configuration, without the secret entering a transcript.
 */
class GetConfig extends AbstractTool
{
    private const REDACTED = '***redacted***';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigPathPolicy $policy
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigPathPolicy $policy,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read store configuration. Pass a full path such as "catalog/frontend/list_mode" for one '
            . 'value, or a prefix such as "catalog/frontend" for the whole group. '
            . 'Values that look like credentials are returned redacted.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Configuration path or path prefix, e.g. "catalog/frontend" '
                        . 'or "catalog/frontend/grid_per_page". At least a section and a group '
                        . 'are required; a bare section is refused because the payload is unusably large.',
                ],
                'store_code' => $this->storeResolver->schemaProperty(),
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Config::config';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $path = strtolower(trim($this->requireString($arguments, 'path'), '/'));

        // Without a floor on the number of segments, a path of "/" trims to ""
        // and ScopeConfig returns the whole merged configuration for the scope.
        // Redaction only catches paths whose *name* marks them as secret, so a
        // third-party credential stored under an innocuous name would be
        // returned verbatim in a payload no agent asked for.
        if (substr_count($path, '/') < 1) {
            throw new LocalizedException(__(
                'Give at least a section and a group, e.g. "catalog/frontend" for a group or '
                . '"catalog/frontend/grid_per_page" for one value. Got "%1".',
                $path
            ));
        }

        $storeCode = $this->optionalString($arguments, 'store_code');
        $storeId = $this->storeResolver->resolve($storeCode);
        $scope = $storeId === 0 ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORE;

        $value = $this->scopeConfig->getValue($path, $scope, $storeId ?: null);
        if ($value === null) {
            throw new LocalizedException(
                __('Nothing is configured at "%1" for this scope.', $path)
            );
        }

        return [
            'path' => $path,
            'store_code' => $storeCode ?? 'admin',
            'store_id' => $storeId,
            'values' => is_array($value) ? $this->redactTree($path, $value) : $this->redactLeaf($path, $value),
        ];
    }

    /**
     * Walk a config group, redacting secret leaves.
     *
     * @param string $prefix
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function redactTree(string $prefix, array $tree): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $childPath = $prefix . '/' . $key;
            $out[$key] = is_array($value)
                ? $this->redactTree($childPath, $value)
                : $this->redactLeaf($childPath, $value);
        }

        return $out;
    }

    /**
     * @param string $path
     * @param mixed $value
     * @return mixed
     */
    private function redactLeaf(string $path, mixed $value): mixed
    {
        if ($this->policy->isSecret($path) || $this->policy->looksEncrypted(is_string($value) ? $value : null)) {
            return $value === null || $value === '' ? $value : self::REDACTED;
        }

        return $value;
    }
}
