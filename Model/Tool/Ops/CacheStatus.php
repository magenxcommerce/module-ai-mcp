<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;

/**
 * Report every cache type's state.
 *
 * The missing half of flush_cache, and the cache-side counterpart of
 * indexer_status: a change that "is not showing up" is usually one of these
 * three things — a type that is invalidated, a type that is enabled and stale,
 * or a type that was switched off entirely.
 */
class CacheStatus extends AbstractTool
{
    /**
     * @param TypeListInterface $cacheTypeList
     * @param StateInterface $cacheState
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly StateInterface $cacheState
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cache_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List every Magento cache type with its code, label, whether it is enabled and '
            . 'whether it has been invalidated. An invalidated type is holding stale content until '
            . 'flush_cache cleans it; a disabled type is not caching at all, which is a '
            . 'performance problem rather than a staleness one.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
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
    public function execute(array $arguments): array
    {
        $invalidated = array_map('strval', array_keys($this->cacheTypeList->getInvalidated()));

        $types = [];
        foreach ($this->cacheTypeList->getTypes() as $code => $type) {
            $code = (string) $code;
            $types[] = [
                'code' => $code,
                'label' => (string) $type->getCacheType(),
                'description' => (string) $type->getDescription(),
                'enabled' => $this->cacheState->isEnabled($code),
                'invalidated' => in_array($code, $invalidated, true),
            ];
        }

        return ['types' => $types, 'invalidated' => $invalidated];
    }
}
