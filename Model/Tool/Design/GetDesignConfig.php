<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Design;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Theme\Api\DesignConfigRepositoryInterface;
use Magento\Theme\Api\Data\DesignConfigDataInterface;

/**
 * Read the design configuration a scope resolves — theme, logo, watermarks,
 * transactional e-mail header and footer.
 */
class GetDesignConfig extends AbstractTool
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
        return 'get_design_config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a scope\'s design configuration: the theme it uses, its logo and favicon, '
            . 'product image watermarks, and the header and footer of transactional e-mails. '
            . 'Values are reported as resolved for that scope, so a store view with no override of '
            . 'its own shows what it inherits. Omit both codes for the default scope.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->scope->schemaProperties(),
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
    public function execute(array $arguments): array
    {
        [$scopeName, $scopeId] = $this->scope->resolve($arguments);
        $config = $this->designConfigRepository->getByScope($scopeName, $scopeId);

        $values = [];
        $extension = $config->getExtensionAttributes();
        foreach ($extension?->getDesignConfigData() ?? [] as $item) {
            /** @var DesignConfigDataInterface $item */
            $values[(string) $item->getPath()] = $item->getValue();
        }

        return [
            'scope' => $scopeName,
            'scope_id' => $scopeId,
            'values' => $values,
        ];
    }
}
