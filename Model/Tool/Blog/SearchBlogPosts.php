<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\Post;
use Magenx\Blog\Model\ResourceModel\Post\CollectionFactory;

/**
 * Find blog posts.
 *
 * Uses the module's own collection filters rather than raw field conditions:
 * `addCategoryFilter` and `addTagFilter` join the link tables, which a plain
 * `addFieldToFilter` on the post row cannot do.
 */
class SearchBlogPosts extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param PostProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PostProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_blog_posts';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search blog posts by text, category, tag, store view or publication state, newest '
            . 'first. The article body is never returned here — twenty posts would be twenty '
            . 'articles; get_blog_post reads one in full. Drafts are included unless you filter '
            . 'with is_active.';
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
                    'query' => [
                        'type' => 'string',
                        'description' => 'Free text matched as a substring against title, url_key '
                            . 'and short_description.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'True for published posts, false for drafts.',
                    ],
                    'category_id' => [
                        'type' => 'integer',
                        'description' => 'Only posts filed under this blog category.',
                    ],
                    'tag_id' => ['type' => 'integer', 'description' => 'Only posts with this tag.'],
                    'store_id' => [
                        'type' => 'integer',
                        'description' => 'Only posts visible on this store view.',
                    ],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Column to sort by. Defaults to publish_date.',
                    ],
                    'sort_direction' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Blog::post';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            $like = ['like' => '%' . $query . '%'];
            $collection->addFieldToFilter(
                ['title', 'url_key', 'short_description'],
                [$like, $like, $like]
            );
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $categoryId = $this->optionalInt($arguments, 'category_id');
        if ($categoryId !== null) {
            $collection->addCategoryFilter($categoryId);
        }
        $tagId = $this->optionalInt($arguments, 'tag_id');
        if ($tagId !== null) {
            $collection->addTagFilter($tagId);
        }
        $storeId = $this->optionalInt($arguments, 'store_id');
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }

        $sortBy = (string) $this->optionalString($arguments, 'sort_by', 'publish_date');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $collection->setOrder($sortBy, $direction === 'ASC' ? 'ASC' : 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $post) {
            /** @var Post $post */
            $items[] = $this->projector->toSummary($post);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
