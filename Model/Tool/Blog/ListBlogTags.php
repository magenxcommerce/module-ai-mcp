<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\ResourceModel\Tag\CollectionFactory;

/**
 * The blog's tags, which posts carry by id.
 */
class ListBlogTags extends AbstractTool
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
        return 'list_blog_tags';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the blog tags with their ids, names and URL keys. The ids are what '
            . 'create_blog_post and update_blog_post take in tag_ids.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->pagingSchema(),
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
        return 'Magenx_Blog::tag';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $collection->setOrder('name', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'tag_id' => (int) $row->getId(),
                'name' => $row->getData('name'),
                'url_key' => $row->getData('url_key'),
                'description' => $row->getData('description'),
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
