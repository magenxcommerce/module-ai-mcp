<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\ResourceModel\Category\CollectionFactory;

/**
 * The blog's categories, which posts are filed under by id.
 */
class ListBlogCategories extends AbstractTool
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
        return 'list_blog_categories';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the blog categories with their ids, names and URL keys. The ids are what '
            . 'create_blog_post and update_blog_post take in category_ids.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                ['is_active' => ['type' => 'boolean', 'description' => 'Restrict to active or inactive.']],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Blog::category';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }
        $collection->setOrder('position', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'category_id' => (int) $row->getId(),
                'name' => $row->getData('name'),
                'url_key' => $row->getData('url_key'),
                'description' => $row->getData('description'),
                'is_active' => (bool) $row->getData('is_active'),
                'position' => (int) $row->getData('position'),
                'meta_title' => $row->getData('meta_title'),
                'meta_description' => $row->getData('meta_description'),
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
