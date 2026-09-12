<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\PageRepositoryInterface;

/**
 * Delete a CMS page.
 */
class DeleteCmsPage extends AbstractTool
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
        return 'delete_cms_page';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a CMS page. This cannot be undone and the page\'s url starts '
            . 'returning a 404 immediately, including from anywhere that links to it. To take a '
            . 'page down reversibly, set is_active false with update_cms_page instead. Behind its '
            . 'own ACL resource, separate from the one that allows editing pages.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cms::page_delete';
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

        // Captured before the delete so the result names the page that is now
        // gone rather than only the identifier that was passed in.
        $deleted = $this->projector->toSummary($page);

        $this->pageRepository->delete($page);

        return ['deleted' => true, 'tool' => $this->getName()] + $deleted;
    }
}
