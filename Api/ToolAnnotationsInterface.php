<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Api;

/**
 * The optional display metadata MCP calls a tool's title and annotations.
 *
 * Deliberately a second interface rather than three more methods on
 * {@see ToolInterface}: that one is `@api` and tools contributed by other
 * modules implement it directly, so adding to it would break every one of them
 * on upgrade. A tool that does not implement this interface is advertised
 * exactly as it was before — name, description and input schema.
 *
 * {@see \Magenx\AiMcp\Model\Tool\AbstractTool} implements it for every tool in
 * this module by deriving the hints from `isWrite()`, so a new tool gets them
 * without doing anything.
 *
 * These are hints for how a client presents and orders tool calls. They are
 * **not** a security boundary and nothing here is enforced: the ACL check, the
 * store write switch and the confirm gate in
 * {@see \Magenx\AiMcp\Model\Protocol\Server} are what actually decide whether a
 * call is allowed to change anything.
 *
 * @api
 */
interface ToolAnnotationsInterface
{
    /**
     * Human-readable name for a client's tool list, e.g. "Search CMS Pages".
     *
     * Null means the client should fall back to the snake_case tool name.
     *
     * @return string|null
     */
    public function getTitle(): ?string;

    /**
     * The MCP `annotations` object, as boolean hints keyed by their spec names:
     * `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`.
     *
     * Returning an empty array advertises no annotations at all, which is not
     * the same as advertising every hint at its default.
     *
     * @return array<string, bool>
     */
    public function getAnnotations(): array;
}
