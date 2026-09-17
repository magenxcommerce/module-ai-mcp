<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\PostRepository;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change an existing blog post.
 *
 * Only the fields passed are touched, including the relation sets: leaving
 * `category_ids` out keeps the post's categories, because the module's resource
 * model rewrites those tables only when the model carries the key at all.
 */
class UpdateBlogPost extends AbstractTool
{
    /**
     * @param PostLocator $locator
     * @param PostRepository $postRepository
     * @param PostArguments $arguments
     * @param PostProjector $projector
     */
    public function __construct(
        private readonly PostLocator $locator,
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
        return 'update_blog_post';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a blog post. Only the fields you pass are changed — a field left out keeps '
            . 'its value, and so do categories, tags and store views unless you pass those sets. '
            . 'Passing an empty category_ids or tag_ids detaches them all, which is the one case '
            . 'where omitting and passing an empty array mean different things.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                $this->arguments->schemaProperties(false)
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
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
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

        $changed = $this->arguments->applyTo($post, $arguments, false);
        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides the post identifier.')
            );
        }

        $this->postRepository->save($post);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toDetail($this->postRepository->getById((int) $post->getId()));
    }
}
