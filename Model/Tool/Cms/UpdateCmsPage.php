<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change a CMS page's content and metadata.
 */
class UpdateCmsPage extends AbstractTool
{
    /**
     * @param PageLocator $locator
     * @param PageRepositoryInterface $pageRepository
     * @param PageContentArguments $content
     * @param PageProjector $projector
     */
    public function __construct(
        private readonly PageLocator $locator,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageContentArguments $content,
        private readonly PageProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_cms_page';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update a CMS page\'s title, body, metadata or active state. Only the fields you '
            . 'pass are changed, but content replaces the whole body — read it first with '
            . 'get_cms_page and include_content. Design and layout XML are not touched here; '
            . 'update_cms_page_design writes those.';
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
                    'new_identifier' => [
                        'type' => 'string',
                        'description' => 'Move the page to this url key. Named separately from '
                            . '"identifier" so looking a page up cannot be confused with moving '
                            . 'it — and moving it changes the path the page is served at.',
                    ],
                ],
                $this->content->schemaProperties()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cms::save';
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

        $changed = $this->content->applyTo($page, $arguments);

        $newIdentifier = $this->optionalString($arguments, 'new_identifier');
        if ($newIdentifier !== null) {
            $page->setIdentifier($newIdentifier);
            $changed[] = 'identifier';
        }

        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides the page identifier.')
            );
        }

        $saved = $this->pageRepository->save($page);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toSummary($saved);
    }
}
