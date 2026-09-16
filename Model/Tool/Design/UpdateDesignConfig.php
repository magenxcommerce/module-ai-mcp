<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Design;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Theme\Api\Data\DesignConfigDataInterface;
use Magento\Theme\Api\DesignConfigRepositoryInterface;

/**
 * Change design configuration for one scope.
 *
 * The repository is loaded and its own value objects are mutated, rather than a
 * config row being written directly: design configuration is validated and
 * cached as a set, and a value written around that is a value the storefront
 * may not pick up.
 */
class UpdateDesignConfig extends AbstractTool
{
    /**
     * @param DesignConfigRepositoryInterface $designConfigRepository
     * @param DesignConfigScope $scope
     */
    public function __construct(
        private readonly DesignConfigRepositoryInterface $designConfigRepository,
        private readonly DesignConfigScope $scope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_design_config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change design configuration for one scope — the theme, logo alt text, watermarks, '
            . 'or transactional e-mail header and footer. Pass values as path/value pairs exactly '
            . 'as get_design_config reports the paths; a path that scope does not have is refused '
            . 'rather than silently ignored. Scope works the way it does everywhere in this '
            . 'server: omitting both codes writes the default that every website and store view '
            . 'inherits, and passing one writes an override for that scope only. Changing the '
            . 'theme changes how every page of that scope renders.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->scope->schemaProperties(),
                [
                    'values' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => [
                                    'type' => 'string',
                                    'description' => 'Design path, e.g. "design/header/logo_alt", '
                                        . 'as get_design_config reports it.',
                                ],
                                'value' => [
                                    'type' => ['string', 'number', 'boolean', 'null'],
                                    'description' => 'The new value.',
                                ],
                            ],
                            'required' => ['path', 'value'],
                            'additionalProperties' => false,
                        ],
                        'description' => 'The paths to change. Anything not listed is left alone.',
                    ],
                ]
            ),
            'required' => ['values'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Theme::design_config';
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        [$scopeName, $scopeId] = $this->scope->resolve($arguments);
        $config = $this->designConfigRepository->getByScope($scopeName, $scopeId);

        /** @var array<int, DesignConfigDataInterface> $items */
        $items = $config->getExtensionAttributes()?->getDesignConfigData() ?? [];
        $byPath = [];
        foreach ($items as $item) {
            $byPath[(string) $item->getPath()] = $item;
        }

        $wanted = $this->wantedValues($arguments);
        $unknown = array_values(array_diff(array_keys($wanted), array_keys($byPath)));
        if ($unknown !== []) {
            // Magento accepts and drops a path this scope does not carry, so the
            // write would report success and change nothing.
            throw new LocalizedException(__(
                'The %1 scope has no design path %2. get_design_config reports the paths it has.',
                $scopeName,
                implode(', ', array_map(static fn (string $p): string => '"' . $p . '"', $unknown))
            ));
        }

        foreach ($wanted as $path => $value) {
            $byPath[$path]->setValue($value);
        }

        $this->designConfigRepository->save($config);

        return [
            'updated' => true,
            'scope' => $scopeName,
            'scope_id' => $scopeId,
            'changed_paths' => array_keys($wanted),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function wantedValues(array $arguments): array
    {
        $wanted = [];
        foreach ($this->optionalArray($arguments, 'values') as $entry) {
            if (!is_array($entry)) {
                throw new LocalizedException(__('Every entry in "values" must be an object.'));
            }

            $path = $entry['path'] ?? null;
            if (!is_string($path) || trim($path) === '') {
                throw new LocalizedException(__('Every entry in "values" needs a "path".'));
            }

            if (!array_key_exists('value', $entry)) {
                throw new LocalizedException(
                    __('The entry for "%1" needs a "value".', trim($path))
                );
            }

            $wanted[trim($path)] = $entry['value'];
        }

        if ($wanted === []) {
            throw new LocalizedException(__('Pass at least one entry in "values".'));
        }

        return $wanted;
    }
}
