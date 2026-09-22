<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;

/**
 * The set of tools this server exposes, populated from di.xml.
 *
 * Another module can add a tool by contributing to the `tools` argument; it
 * never has to touch the server or the controller.
 *
 * Every tool is registered as a `\Proxy`, so a request builds only the tools it
 * actually reaches rather than all of them. Nothing here may call a method on a
 * held tool, because the first such call is what wakes the proxy and drags in
 * that tool's whole dependency graph — which, done in a loop, is the eager
 * construction the proxies exist to avoid. So the name a tool is filed under
 * comes from the di.xml array key rather than from `getName()`, and the two are
 * held together by {@see \Magenx\AiMcp\Test\Unit\Model\Tool\RegistrationConventionsTest}
 * rather than at runtime.
 *
 * A contribution keyed by position instead of by name still works — the name is
 * then read from the tool, at the cost of building that one tool eagerly.
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param ToolInterface[] $tools Keyed by tool name, as di.xml keys them.
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $key => $tool) {
            // An `instanceof` check reads the class hierarchy, which a proxy
            // already satisfies by extending its subject. It does not wake one.
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $this->tools[is_string($key) && $key !== '' ? $key : $tool->getName()] = $tool;
        }
        ksort($this->tools);
    }

    /**
     * Every registered tool, built.
     *
     * Wakes every proxy, so prefer {@see getNames()} and {@see get()} on any
     * path that will not go on to use all of them.
     *
     * @return array<string, ToolInterface>
     */
    public function getAll(): array
    {
        return $this->tools;
    }

    /**
     * Every registered tool name, without building anything.
     *
     * @return string[]
     */
    public function getNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * The class each tool is registered as, without building anything.
     *
     * These are the classes the object manager handed over, so a proxied or
     * intercepted tool reports the generated class rather than its own. Callers
     * that derive meaning from the name must account for that;
     * {@see ToolCatalog} is the one that does.
     *
     * @return array<string, class-string>
     */
    public function getClasses(): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool::class, $this->tools);
    }

    /**
     * The class one tool is registered as, or null if no such tool.
     *
     * @param string $name
     * @return class-string|null
     */
    public function getClass(string $name): ?string
    {
        $tool = $this->tools[$name] ?? null;

        return $tool === null ? null : $tool::class;
    }

    /**
     * One built tool, or null if no such tool.
     *
     * @param string $name
     * @return ToolInterface|null
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }
}
