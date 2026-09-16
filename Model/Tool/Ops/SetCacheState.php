<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Switch a cache type on or off.
 *
 * A write with no data change, the same reasoning flush_cache carries: it is an
 * operational action with a visible effect on a live site.
 */
class SetCacheState extends AbstractTool
{
    /**
     * Magento can run with any other cache type off, slowly. With the
     * configuration cache off it re-reads and re-merges every config file on
     * every request, which on a live store is an outage rather than a
     * slowdown — and re-enabling it through this same endpoint would be the
     * first thing to time out.
     */
    private const PROTECTED_TYPES = ['config'];

    /**
     * @param StateInterface $cacheState
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly StateInterface $cacheState,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_cache_state';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Enable or disable Magento cache types. Disabling one does not clear it, it stops '
            . 'it being used — on a live store, disabling full_page is a performance incident, not '
            . 'a debugging convenience, so switch it back on when you are done. The configuration '
            . 'cache cannot be disabled through this server at all. To clear a cache rather than '
            . 'switch it off, use flush_cache.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Cache type codes, e.g. ["block_html", "full_page"]. '
                        . 'cache_status reports the codes this installation has.',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'True to switch the named types on, false to switch them off.',
                ],
            ],
            'required' => ['types', 'enabled'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Backend::cache';
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
    protected function isIdempotent(): bool
    {
        // Setting a cache type to the state it is already in changes nothing.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $enabled = $this->optionalBool($arguments, 'enabled');
        if ($enabled === null) {
            throw new LocalizedException(__('The "enabled" argument is required: true or false.'));
        }

        $available = array_map('strval', array_keys($this->cacheTypeList->getTypes()));
        $requested = array_values(array_filter($this->optionalArray($arguments, 'types'), 'is_string'));
        if ($requested === []) {
            throw new LocalizedException(__(
                'Pass at least one cache type in "types". Available types are: %1.',
                implode(', ', $available)
            ));
        }

        $unknown = array_values(array_diff($requested, $available));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Unknown cache types: %1. Available types are: %2.',
                implode(', ', $unknown),
                implode(', ', $available)
            ));
        }

        if (!$enabled) {
            $protected = array_values(array_intersect($requested, self::PROTECTED_TYPES));
            if ($protected !== []) {
                throw new LocalizedException(__(
                    'The %1 cache cannot be disabled through this server: Magento would re-read '
                    . 'every configuration file on every request, and re-enabling it here would be '
                    . 'the first thing to fail. Use bin/magento cache:disable if you really mean it.',
                    implode(', ', $protected)
                ));
            }
        }

        foreach ($requested as $type) {
            $this->cacheState->setEnabled($type, $enabled);
        }
        $this->cacheState->persist();

        return [
            'updated' => true,
            'enabled' => $enabled,
            'types' => $requested,
            'available_types' => $available,
        ];
    }
}
