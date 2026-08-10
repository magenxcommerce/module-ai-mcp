<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Clean Magento cache types.
 *
 * Counts as a write because it is an operational action with a visible effect
 * on a live site, even though it changes no data.
 */
class FlushCache extends AbstractTool
{
    /**
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'flush_cache';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Clean Magento cache types, or all of them. Call this after a configuration or content '
            . 'change that is not showing up. Returns the cache types that were cleaned.';
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
                    'description' => 'Cache type codes such as ["config", "block_html", "full_page"]. '
                        . 'Omit to clean every type. Naming an unknown type fails with the full '
                        . 'list of valid codes, and every successful result repeats it.',
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
    public function execute(array $arguments): array
    {
        $available = [];
        foreach ($this->cacheTypeList->getTypes() as $type) {
            $available[] = is_array($type) ? (string) ($type['id'] ?? '') : (string) $type->getId();
        }
        $available = array_values(array_filter($available));

        $requested = array_values(array_filter($this->optionalArray($arguments, 'types'), 'is_string'));
        if ($requested === []) {
            $requested = $available;
        }

        $unknown = array_values(array_diff($requested, $available));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Unknown cache types: %1. Available types are: %2.',
                implode(', ', $unknown),
                implode(', ', $available)
            ));
        }

        foreach ($requested as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        return ['flushed' => $requested, 'available_types' => $available];
    }
}
