<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Platform\Model\Security\MagentoWatch;
use Magenx\Platform\Model\Security\SecurityReport;

/**
 * The Platform module's Security tab, unrendered.
 *
 * Every finding — the bulletins open against the release, the patch records
 * that close them, the advisories against composer.lock — is made by
 * {@see SecurityReport} in Magenx_Platform, which also lays it out as an admin
 * tab. This tool returns the same report as data, so an agent and an admin
 * reading the tab never disagree, and it sits behind the same ACL resource as
 * the tab and `platform_status`.
 */
class SecurityStatus extends AbstractTool
{
    /**
     * @param SecurityReport $report
     */
    public function __construct(
        private readonly SecurityReport $report
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'security_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Report the installed Magento release, the Adobe bulletins open against it (magento.watch) with '
            . 'whether this store records each isolated patch (composer-patches, composer.json, m2-hotfixes/), '
            . 'and Packagist advisories against composer.lock. "no_evidence" means no record, not proof of '
            . 'absence: a patch applied by hand leaves none.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list_patch_records' => [
                    'type' => 'boolean',
                    'description' => 'Also return every patch record found, not only the ones matched to a '
                        . 'bulletin. Defaults to false.',
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
     * It asks services outside this store, which is exactly what this hint is
     * for — the one kind of read here that is not about this store alone.
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
            $this->optionalString($arguments, 'distribution'),
            (bool) $this->optionalBool($arguments, 'list_patch_records', false)
        );
    }
}
