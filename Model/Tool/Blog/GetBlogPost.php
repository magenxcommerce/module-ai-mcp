<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * One blog post in full, with its relations.
 */
class GetBlogPost extends AbstractTool
{
    /**
     * @param PostLocator $locator
     * @param PostProjector $projector
     */
    public function __construct(
        private readonly PostLocator $locator,
        private readonly PostProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_blog_post';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one blog post in full — the article body, its SEO fields, and the categories, '
            . 'tags, store views and linked products attached to it. Name it by post_id or '
            . 'url_key. search_blog_posts omits the body, so this is how to read the article '
            . 'itself.';
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
    public function execute(array $arguments): array
    {
        return $this->projector->toDetail($this->locator->locate(
            $this->optionalString($arguments, 'url_key'),
            $this->optionalInt($arguments, 'post_id')
        ));
    }
}
