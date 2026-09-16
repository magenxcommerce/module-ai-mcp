<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\Blog\Model\Post;
use Magenx\Blog\Model\PostRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns whichever argument names a post into the post.
 */
class PostLocator
{
    /**
     * @param PostRepository $postRepository
     */
    public function __construct(
        private readonly PostRepository $postRepository
    ) {
    }

    /**
     * @param string|null $urlKey
     * @param int|null $postId
     * @return Post
     * @throws LocalizedException
     */
    public function locate(?string $urlKey, ?int $postId): Post
    {
        if ($postId !== null) {
            try {
                return $this->postRepository->getById($postId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No blog post exists with post_id %1.', $postId));
            }
        }

        if ($urlKey === null) {
            throw new LocalizedException(__('Pass url_key or post_id to name the post.'));
        }

        try {
            return $this->postRepository->getByUrlKey($urlKey);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No blog post exists with url_key "%1".', $urlKey));
        }
    }

    /**
     * Schema fragment for the two arguments that name a post.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'post_id' => ['type' => 'integer', 'description' => 'The post id.'],
            'url_key' => [
                'type' => 'string',
                'description' => 'The post\'s URL key, if the id is not to hand.',
            ],
        ];
    }
}
