<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\Blog\Model\Post;
use Magenx\Blog\Model\PostRepository;

/**
 * One blog post, reduced to what is worth sending.
 *
 * The summary leaves out `content`, which is the whole article: a search over
 * twenty posts would otherwise be twenty articles, and the field a caller wants
 * from a list is the title and whether it is live. `toDetail()` adds the body
 * back, along with the relations the post's own row does not carry.
 */
class PostProjector
{
    /**
     * @param PostRepository $postRepository
     */
    public function __construct(
        private readonly PostRepository $postRepository
    ) {
    }

    /**
     * @param Post $post
     * @return array<string, mixed>
     */
    public function toSummary(Post $post): array
    {
        return [
            'post_id' => (int) $post->getId(),
            'title' => $post->getData('title'),
            'url_key' => $post->getData('url_key'),
            'is_active' => (bool) $post->getData('is_active'),
            'publish_date' => $post->getData('publish_date'),
            'author_name' => $post->getData('author_name'),
            'short_description' => $post->getData('short_description'),
            'created_at' => $post->getData('created_at'),
            'updated_at' => $post->getData('updated_at'),
        ];
    }

    /**
     * @param Post $post
     * @return array<string, mixed>
     */
    public function toDetail(Post $post): array
    {
        $postId = (int) $post->getId();
        $storeIds = array_map('intval', $this->postRepository->getStoreIds($postId));

        return $this->toSummary($post) + [
            'content' => $post->getData('content'),
            'image' => $post->getData('image'),
            'meta_title' => $post->getData('meta_title'),
            'meta_description' => $post->getData('meta_description'),
            'meta_keywords' => $post->getData('meta_keywords'),
            'category_ids' => array_map('intval', $this->postRepository->getCategoryIds($postId)),
            'tag_ids' => array_map('intval', $this->postRepository->getTagIds($postId)),
            'store_ids' => $storeIds,
            // A post with no store rows is invisible on the storefront while
            // still listed in the admin, so the distinction between "every
            // store" and "no store" is worth naming rather than leaving a
            // caller to infer it from an array.
            'visible_in_all_stores' => in_array(0, $storeIds, true),
            'product_ids' => array_map('intval', array_keys($this->postRepository->getProductPositions($postId))),
        ];
    }
}
