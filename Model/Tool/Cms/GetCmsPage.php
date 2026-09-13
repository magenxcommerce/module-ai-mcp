<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one CMS page.
 */
class GetCmsPage extends AbstractTool
{
    /**
     * @param PageLocator $locator
     * @param PageProjector $projector
     */
    public function __construct(
        private readonly PageLocator $locator,
        private readonly PageProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_cms_page';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one CMS page by identifier or page_id: its title, metadata, active state and '
            . 'design settings. The page body is omitted unless you pass include_content, because '
            . 'a content page runs to tens of kilobytes of HTML.';
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
                    'include_content' => [
                        'type' => 'boolean',
                        'description' => 'Return the page body HTML. Default false, which reports '
                            . 'its length instead.',
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
        return 'Magento_Cms::page';
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

        return $this->projector->toDetail(
            $page,
            (bool) $this->optionalBool($arguments, 'include_content', false)
        );
    }
}
