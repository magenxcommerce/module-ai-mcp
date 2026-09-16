<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\ResourceModel\CookieGroup\CollectionFactory;

/**
 * The consent categories the banner offers.
 *
 * Read-only, and this one is a property of the data model rather than a
 * judgement call: the consent log records a decision in four fixed columns —
 * necessary, analytics, marketing, preferences — matching the four seeded
 * groups. A fifth group would appear in the banner with nowhere to store what
 * anyone answered about it, so creating one is not a feature this server is
 * withholding, it is a thing that does not work.
 */
class ListCookieGroups extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_gdpr_cookie_groups';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the cookie consent categories with their ids and codes. The ids are what '
            . 'save_gdpr_cookie takes in group_id. Read-only: the consent log records decisions in '
            . 'four fixed columns matching the four seeded groups, so a new group would have '
            . 'nowhere to store what a visitor answered about it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => $this->pagingSchema(), 'additionalProperties' => false];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Gdpr::cookie_groups';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $collection->setOrder('sort_order', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'group_id' => (int) $row->getId(),
                'code' => $row->getData('code'),
                'label' => $row->getData('label'),
                'description' => $row->getData('description'),
                // A required group cannot be declined, which is what makes it
                // "strictly necessary" rather than a default-on choice.
                'is_required' => (bool) $row->getData('is_required'),
                'is_active' => (bool) $row->getData('is_active'),
                'sort_order' => (int) $row->getData('sort_order'),
            ];
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
