<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\PostRepository;

/**
 * Remove a blog post.
 *
 * The article text goes with it and there is no revision history to fall back
 * on, so the description points at `is_active` first — which takes the post off
 * the storefront, keeps the URL out of circulation, and can be undone by
 * someone who decides next week that it should not have been.
 */
class DeleteBlogPost extends AbstractTool
{
    /**
     * @param PostLocator $locator
     * @param PostRepository $postRepository
     */
    public function __construct(
        private readonly PostLocator $locator,
        private readonly PostRepository $postRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_blog_post';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a blog post, with its category, tag and product links. The '
            . 'article text is not recoverable. To take a post off the storefront while keeping '
            . 'it, set is_active false with update_blog_post instead — that is reversible and is '
            . 'almost always what "remove this post" means.';
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
        return 'Magenx_Blog::post';
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
        $post = $this->locator->locate(
            $this->optionalString($arguments, 'url_key'),
            $this->optionalInt($arguments, 'post_id')
        );

        $postId = (int) $post->getId();
        $title = (string) $post->getData('title');
        $urlKey = (string) $post->getData('url_key');

        $this->postRepository->delete($post);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            // Echoed because after this the audit log line is the only record.
            'post_id' => $postId,
            'title' => $title,
            'url_key' => $urlKey,
        ];
    }
}
