<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

/**
 * The slice of the toolset one connection asked to see.
 *
 * Chosen by the client in the endpoint URL — `?profile=`, `?domains=`,
 * `?tools=` — so an agent can be pointed at the part of the store its task
 * needs without an operator configuring anything. It only ever narrows: the
 * server applies it on top of the store's own toolset settings, ACL and the
 * write switch, never instead of them, so nothing a client puts in its URL can
 * reach a tool those would withhold.
 *
 * Null for either list means that dimension is not restricted; an empty list
 * never reaches here, because {@see ToolsetResolver} refuses a request that
 * would select nothing.
 */
class Toolset
{
    /**
     * @param string[]|null $domains Domain codes, or null for every domain.
     * @param string[]|null $tools Tool names, or null for every tool.
     */
    public function __construct(
        private readonly ?array $domains = null,
        private readonly ?array $tools = null
    ) {
    }

    /**
     * Whether the connection narrowed anything at all.
     *
     * @return bool
     */
    public function isRestricted(): bool
    {
        return $this->domains !== null || $this->tools !== null;
    }

    /**
     * Whether a tool falls inside the slice.
     *
     * Takes the name and domain rather than the tool, for the reason the
     * server's own configuration filter does: both are known before the tool's
     * proxy is woken, and a tool outside the slice should cost nothing.
     *
     * @param string $name
     * @param string $domain
     * @return bool
     */
    public function permits(string $name, string $domain): bool
    {
        return ($this->domains === null || in_array($domain, $this->domains, true))
            && ($this->tools === null || in_array($name, $this->tools, true));
    }
}
