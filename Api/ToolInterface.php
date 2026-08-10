<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Api;

/**
 * One MCP tool.
 *
 * Tools are registered through the `tools` argument of
 * {@see \Magenx\AiMcp\Model\Tool\ToolRegistry} in di.xml, so another module can
 * contribute tools without touching this one.
 *
 * A tool only implements its operation. The server owns every cross-cutting
 * decision — ACL, the write switch, the confirm/preview gate, audit logging —
 * so none of them can be forgotten in a new tool.
 *
 * @api
 */
interface ToolInterface
{
    /**
     * Tool name as the MCP client sees it. Lowercase snake_case, unique.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * One or two sentences telling the agent what this does and when to use it.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * JSON Schema (draft 2020-12 subset) describing the arguments object.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Stock ACL resource the calling integration must hold, e.g.
     * `Magento_Catalog::products`. Returning an empty string means the
     * endpoint-level `Magenx_AiMcp::server` grant is enough.
     *
     * @return string
     */
    public function getAclResource(): string;

    /**
     * Whether this tool changes data. Write tools are hidden and refused unless
     * the store enables them, and require an explicit `confirm` argument.
     *
     * @return bool
     */
    public function isWrite(): bool;

    /**
     * Run the tool.
     *
     * Throw {@see \Magento\Framework\Exception\LocalizedException} for an
     * expected failure (bad input, missing entity) — the server turns it into a
     * tool error the agent can act on. Anything else is treated as a bug.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> Structured result for `structuredContent`.
     */
    public function execute(array $arguments): array;
}
