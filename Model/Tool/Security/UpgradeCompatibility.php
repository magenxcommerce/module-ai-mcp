<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Platform\Model\Security\CompatibilityReport;
use Magenx\Platform\Model\Security\MagentoWatch;

/**
 * The Platform module's Compatibility tab, unrendered.
 *
 * {@see CompatibilityReport} in Magenx_Platform decides everything: PHP is a
 * blocker only when the release's own composer metapackage excludes it, other
 * services are warnings against the versions Adobe tested, and a third-party
 * package whose requirements exclude what the release pins is a blocker. This
 * tool returns that report as data, behind the same ACL resource as the tab.
 */
class UpgradeCompatibility extends AbstractTool
{
    /**
     * @param CompatibilityReport $report
     */
    public function __construct(
        private readonly CompatibilityReport $report
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'upgrade_compatibility';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check whether this store can move to a newer Magento release: PHP against the release\'s '
            . 'composer constraint, the services against its tested versions, and every composer.lock extension '
            . 'against what it pins. Checks the recommended release, the newest patch on this line and the '
            . 'newest release, or target_version.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_version' => [
                    'type' => 'string',
                    'description' => 'Check only this release, e.g. "2.4.9".',
                ],
                'include_outdated' => [
                    'type' => 'boolean',
                    'description' => 'Also list direct dependencies with a newer stable release. One lookup per '
                        . 'package on a cold cache. Defaults to false.',
                ],
                'distribution' => [
                    'type' => 'string',
                    'enum' => MagentoWatch::DISTRIBUTIONS,
                    'description' => 'Override the distribution detected from the edition.',
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
        return 'Magenx_Platform::platform';
    }

    /**
     * See {@see SecurityStatus::getAnnotations()}: this asks services outside
     * the store.
     *
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return ['openWorldHint' => true] + parent::getAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        return $this->report->build(
            $this->optionalString($arguments, 'target_version'),
            $this->optionalString($arguments, 'distribution'),
            (bool) $this->optionalBool($arguments, 'include_outdated', false)
        );
    }
}
