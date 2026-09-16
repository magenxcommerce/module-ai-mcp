<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

use Magenx\AiMcp\Api\ToolAnnotationsInterface;
use Magenx\AiMcp\Api\ToolInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Argument handling shared by every tool, and the annotations derived from it.
 *
 * Tools validate their own arguments rather than relying on the client to
 * honour the advertised JSON Schema — an MCP client is not a trust boundary.
 */
abstract class AbstractTool implements ToolInterface, ToolAnnotationsInterface
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
     * Derived from the tool name, which is already the canonical description of
     * what the tool does. A tool whose name reads badly as a title overrides it.
     *
     * @return string|null
     */
    public function getTitle(): ?string
    {
        $words = array_map(
            static fn (string $word): string => Initialisms::MAP[$word] ?? ucfirst($word),
            explode('_', $this->getName())
        );

        return implode(' ', $words);
    }

    /**
     * The hints, derived so a new tool gets them for free.
     *
     * `destructiveHint` and `idempotentHint` are defined by MCP only when
     * `readOnlyHint` is false, so a read tool advertises neither rather than
     * advertising a value a client is entitled to ignore.
     *
     * @return array<string, bool>
     */
    public function getAnnotations(): array
    {
        $annotations = [
            'readOnlyHint' => !$this->isWrite(),
            // Every tool here acts on this one Magento store. None of them
            // reaches an open-ended set of external entities, which is what
            // openWorldHint warns a client about.
            'openWorldHint' => false,
        ];

        if ($this->isWrite()) {
            $annotations['destructiveHint'] = $this->isDestructive();
            $annotations['idempotentHint'] = $this->isIdempotent();
        }

        return $annotations;
    }

    /**
     * Whether this tool can remove or overwrite something that was already
     * there. Defaults to MCP's own default of true — assume the worst of a
     * write — so a tool that only ever adds has to say so deliberately.
     *
     * @return bool
     */
    protected function isDestructive(): bool
    {
        return true;
    }

    /**
     * Whether calling this tool twice with the same arguments leaves the store
     * in the same state as calling it once. False by default, which is MCP's
     * default and is correct for every `create_`/`add_` tool; the `set_` and
     * `update_` tools override it.
     *
     * @return bool
     */
    protected function isIdempotent(): bool
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
     * A boolean argument, or the default when it was not supplied.
     *
     * Deliberately not a `(bool)` cast: JSON has real booleans, and a cast
     * turns the string "false" — which a model does produce — into true, which
     * is the kind of silent wrong write this server exists to avoid.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @param bool|null $default
     * @return bool|null
     * @throws LocalizedException
     */
    protected function optionalBool(array $arguments, string $key, ?bool $default = null): ?bool
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return $default;
        }

        $value = $arguments[$key];
        if (!is_bool($value)) {
            throw new LocalizedException(
                __('The "%1" argument must be true or false, not a string or a number.', $key)
            );
        }

        return $value;
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
