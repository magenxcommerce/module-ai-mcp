<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Argument handling shared by every tool.
 *
 * Tools validate their own arguments rather than relying on the client to
 * honour the advertised JSON Schema — an MCP client is not a trust boundary.
 */
abstract class AbstractTool implements ToolInterface
{
    /** Nothing may ask for an unbounded page: results are fed to a model. */
    protected const MAX_PAGE_SIZE = 100;
    protected const DEFAULT_PAGE_SIZE = 20;

    /**
     * Most tools read; the ones that write say so.
     *
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new LocalizedException(__('The "%1" argument is required.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return int
     * @throws LocalizedException
     */
    protected function requireInt(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new LocalizedException(__('The "%1" argument must be a whole number.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    protected function optionalString(array $arguments, string $key, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return int|null
     */
    protected function optionalInt(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return array<mixed>
     */
    protected function optionalArray(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return int
     */
    protected function pageSize(array $arguments): int
    {
        $size = $this->optionalInt($arguments, 'page_size') ?? self::DEFAULT_PAGE_SIZE;

        return max(1, min($size, self::MAX_PAGE_SIZE));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return int
     */
    protected function currentPage(array $arguments): int
    {
        return max(1, $this->optionalInt($arguments, 'page') ?? 1);
    }

    /**
     * Schema fragment for the paging arguments every list tool accepts.
     *
     * @return array<string, mixed>
     */
    protected function pagingSchema(): array
    {
        return [
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, 1-based.'],
            'page_size' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => self::MAX_PAGE_SIZE,
                'description' => 'Results per page (default ' . self::DEFAULT_PAGE_SIZE
                    . ', maximum ' . self::MAX_PAGE_SIZE . ').',
            ],
        ];
    }
}
