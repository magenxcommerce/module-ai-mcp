<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Config;

use Magenx\AiMcp\Model\ConfigPathPolicy;
use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * Write a store configuration value.
 *
 * This is the tool stock Magento REST has no equivalent for, and the most
 * dangerous one here — configuration can disable payment, change base URLs or
 * turn off a security feature. It is therefore gated three ways: the store's
 * write switch, the store's path allowlist, and a hard-coded denylist of
 * secret-bearing and protected paths that no allowlist can open.
 */
class SetConfig extends AbstractTool
{
    /**
     * @param WriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param ReinitableConfigInterface $reinitableConfig
     * @param TypeListInterface $cacheTypeList
     * @param ConfigPathPolicy $policy
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ConfigPathPolicy $policy,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Write a store configuration value and refresh the configuration cache. '
            . 'Only paths an administrator has listed as writable are accepted; '
            . 'credential-bearing paths are always refused.';
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
                    'description' => 'Full configuration path, e.g. "catalog/frontend/grid_per_page".',
                ],
                'value' => [
                    'type' => ['string', 'number', 'boolean', 'null'],
                    'description' => 'The new value. Magento stores configuration as strings; '
                        . 'booleans are written as "1" and "0".',
                ],
                'store_code' => $this->storeResolver->schemaProperty(),
            ],
            'required' => ['path', 'value'],
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
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $path = trim($this->requireString($arguments, 'path'), '/');
        if (substr_count($path, '/') !== 2) {
            throw new LocalizedException(
                __('A configuration path has three segments, e.g. "catalog/frontend/grid_per_page". Got "%1".', $path)
            );
        }

        $refusal = $this->policy->refuseWriteReason($path);
        if ($refusal !== null) {
            throw new LocalizedException(__($refusal));
        }

        if (!array_key_exists('value', $arguments)) {
            throw new LocalizedException(__('The "value" argument is required.'));
        }
        $value = $this->normalizeValue($arguments['value']);

        $storeCode = $this->optionalString($arguments, 'store_code');
        $storeId = $this->storeResolver->resolve($storeCode);
        $scope = $storeId === 0 ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORES;

        $previous = $this->scopeConfig->getValue(
            $path,
            $storeId === 0 ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORE,
            $storeId ?: null
        );

        $this->configWriter->save($path, $value, $scope, $storeId);

        // A configuration write is invisible until the config cache is
        // refreshed; doing it here is what makes the tool's result truthful.
        $this->reinitableConfig->reinit();
        $this->cacheTypeList->cleanType('config');

        return [
            'updated' => true,
            'path' => $path,
            'store_code' => $storeCode ?? 'admin',
            'store_id' => $storeId,
            'previous_value' => is_array($previous) ? null : $previous,
            'new_value' => $value,
            'note' => 'The configuration cache was refreshed. A headless storefront may still serve a '
                . 'cached copy until its own cache is purged.',
        ];
    }

    /**
     * Magento stores configuration as strings.
     *
     * @param mixed $value
     * @return string
     * @throws LocalizedException
     */
    private function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        throw new LocalizedException(__('The "value" argument must be a string, number, boolean or null.'));
    }
}
