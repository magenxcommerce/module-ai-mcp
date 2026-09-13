<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;

/**
 * Report what is installed and what is switched on.
 */
class ListModules extends AbstractTool
{
    /**
     * @param ModuleListInterface $moduleList
     * @param FullModuleList $fullModuleList
     * @param PackageInfo $packageInfo
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly FullModuleList $fullModuleList,
        private readonly PackageInfo $packageInfo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_modules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Magento modules installed on this store, with each one\'s composer '
            . 'package, version and whether it is enabled. This is how to check that a feature '
            . 'another tool depends on is actually present — a disabled module is installed and '
            . 'inert, which looks from the outside like a tool that silently does nothing.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name_contains' => [
                    'type' => 'string',
                    'description' => 'Restrict to module names containing this, e.g. "Magenx" or '
                        . '"Inventory". Case-insensitive.',
                ],
                'enabled_only' => [
                    'type' => 'boolean',
                    'description' => 'Omit the modules that are installed but switched off. '
                        . 'Defaults to false, because a disabled module is usually what is being '
                        . 'looked for.',
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
        return 'Magenx_AiMcp::ops';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $enabledNames = $this->moduleList->getNames();
        $filter = $this->optionalString($arguments, 'name_contains');
        $enabledOnly = (bool) $this->optionalBool($arguments, 'enabled_only', false);

        $modules = [];
        foreach ($this->fullModuleList->getNames() as $name) {
            $name = (string) $name;
            if ($filter !== null && stripos($name, $filter) === false) {
                continue;
            }

            $enabled = in_array($name, $enabledNames, true);
            if ($enabledOnly && !$enabled) {
                continue;
            }

            $modules[] = [
                'name' => $name,
                'package' => $this->packageInfo->getPackageName($name) ?: null,
                'version' => $this->packageInfo->getVersion($name) ?: null,
                'enabled' => $enabled,
            ];
        }

        return [
            'total_installed' => count($this->fullModuleList->getNames()),
            'total_enabled' => count($enabledNames),
            'modules' => $modules,
        ];
    }
}
