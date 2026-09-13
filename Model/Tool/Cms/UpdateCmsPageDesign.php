<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change a CMS page's design fields.
 *
 * Separate from update_cms_page because Magento gates these behind their own
 * ACL resource, Magento_Cms::save_design, and a tool declares exactly one
 * resource. Keeping them apart means an integration granted "Save Page" can
 * edit copy without also being able to inject layout XML.
 */
class UpdateCmsPageDesign extends AbstractTool
{
    /**
     * @param PageLocator $locator
     * @param PageRepositoryInterface $pageRepository
     * @param PageProjector $projector
     */
    public function __construct(
        private readonly PageLocator $locator,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_cms_page_design';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a CMS page\'s design: its theme, root template and layout update XML. Only '
            . 'the fields you pass are changed, and each can be passed as null to clear it. Layout '
            . 'XML that Magento cannot validate is refused on save. This sits behind a different '
            . 'permission from the rest of page editing, so it may be unavailable even when '
            . 'update_cms_page is not.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'layout_update_xml' => [
                        'type' => ['string', 'null'],
                        'description' => 'Layout update XML for this page. Null clears it.',
                    ],
                    'custom_layout_update_xml' => [
                        'type' => ['string', 'null'],
                        'description' => 'Layout update XML applied only during the custom design '
                            . 'window. Null clears it.',
                    ],
                    'custom_theme' => [
                        'type' => ['string', 'null'],
                        'description' => 'Theme id to use during the custom design window. Null clears it.',
                    ],
                    'custom_root_template' => [
                        'type' => ['string', 'null'],
                        'description' => 'Layout handle to use during the custom design window, e.g. '
                            . '"1column". Null clears it.',
                    ],
                    'custom_theme_from' => [
                        'type' => ['string', 'null'],
                        'description' => 'First day of the custom design window, as "YYYY-MM-DD". '
                            . 'Null clears it.',
                    ],
                    'custom_theme_to' => [
                        'type' => ['string', 'null'],
                        'description' => 'Last day of the custom design window, as "YYYY-MM-DD". '
                            . 'Null clears it.',
                    ],
                ]
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cms::save_design';
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
    public function execute(array $arguments): array
    {
        $page = $this->locator->locate(
            $this->optionalString($arguments, 'identifier'),
            $this->optionalInt($arguments, 'page_id')
        );

        $changed = [];
        foreach ($this->designSetters() as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(
                    __('The "%1" argument must be a string, or null to clear it.', $key)
                );
            }
            $page->{$setter}($value);
            $changed[] = $key;
        }

        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one design field besides the page identifier.')
            );
        }

        $saved = $this->pageRepository->save($page);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toDetail($saved, false);
    }

    /**
     * @return array<string, string>
     */
    private function designSetters(): array
    {
        return [
            'layout_update_xml' => 'setLayoutUpdateXml',
            'custom_layout_update_xml' => 'setCustomLayoutUpdateXml',
            'custom_theme' => 'setCustomTheme',
            'custom_root_template' => 'setCustomRootTemplate',
            'custom_theme_from' => 'setCustomThemeFrom',
            'custom_theme_to' => 'setCustomThemeTo',
        ];
    }
}
