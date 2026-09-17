<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\PostFactory;
use Magenx\Blog\Model\PostRepository;

/**
 * Publish a new blog post.
 */
class CreateBlogPost extends AbstractTool
{
    /**
     * @param PostFactory $postFactory
     * @param PostRepository $postRepository
     * @param PostArguments $arguments
     * @param PostProjector $projector
     */
    public function __construct(
        private readonly PostFactory $postFactory,
        private readonly PostRepository $postRepository,
        private readonly PostArguments $arguments,
        private readonly PostProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_blog_post';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a blog post. Title and content are required; the URL key is derived from '
            . 'the title unless you pass one. A new post is active and visible on every store view '
            . 'by default, so pass is_active false to draft it. Categories and tags are attached by '
            . 'id — list_blog_categories and list_blog_tags report them.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties(true),
            'required' => ['title', 'content'],
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
    protected function isDestructive(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $post = $this->postFactory->create();
        $changed = $this->arguments->applyTo($post, $arguments, true);
        $this->postRepository->save($post);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toDetail($this->postRepository->getById((int) $post->getId()));
    }
}
