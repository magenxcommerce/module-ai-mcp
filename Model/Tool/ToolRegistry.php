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
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$tool->getName()] = $tool;
            }
        }
        ksort($this->tools);
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getAll(): array
    {
        return $this->tools;
    }

    /**
     * @param string $name
     * @return ToolInterface|null
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }
}
