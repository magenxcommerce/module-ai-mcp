<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;

/**
 * Create a CMS page.
 */
class CreateCmsPage extends AbstractTool
{
    /**
     * @param PageRepositoryInterface $pageRepository
     * @param PageInterfaceFactory $pageFactory
     * @param PageContentArguments $content
     * @param PageProjector $projector
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly PageContentArguments $content,
        private readonly PageProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_cms_page';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a CMS page. The identifier is its url key, so the page becomes reachable at '
            . 'that path as soon as it is active. Create it with is_active false to stage it. '
            . 'Design and layout XML are set separately by update_cms_page_design.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'The page\'s url key, e.g. "about-us". Becomes the path the '
                            . 'page is served at.',
                    ],
                ],
                $this->content->schemaProperties()
            ),
            'required' => ['identifier', 'title'],
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
    protected function isDestructive(): bool
    {
        // Adds a page; nothing that already exists is touched.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $identifier = $this->requireString($arguments, 'identifier');
        $this->requireString($arguments, 'title');

        $page = $this->pageFactory->create();
        $page->setIdentifier($identifier);

        // Unless the caller says otherwise a new page is published, which is
        // what the admin does; the description says how to stage one instead.
        $page->setIsActive($this->optionalBool($arguments, 'is_active', true));

        $changed = $this->content->applyTo($page, $arguments);

        $saved = $this->pageRepository->save($page);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'fields_set' => $changed,
        ] + $this->projector->toSummary($saved);
    }
}
