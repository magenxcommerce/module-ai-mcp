<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\TagFactory;
use Magenx\Blog\Model\TagRepository;
use Magenx\Blog\Model\UrlKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Create or rename a blog tag.
 */
class SaveBlogTag extends AbstractTool
{
    /**
     * @param TagFactory $tagFactory
     * @param TagRepository $tagRepository
     * @param UrlKey $urlKey
     */
    public function __construct(
        private readonly TagFactory $tagFactory,
        private readonly TagRepository $tagRepository,
        private readonly UrlKey $urlKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_blog_tag';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create or update a blog tag. Omit tag_id to create one; pass it to change an '
            . 'existing one, in which case only the fields you pass are changed. The URL key is '
            . 'derived from the name unless you pass one, and changing it moves the tag\'s '
            . 'storefront address — anything linking to the old one breaks.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag_id' => [
                    'type' => 'integer',
                    'description' => 'The tag to change. Omit to create a new one.',
                ],
                'name' => ['type' => 'string', 'description' => 'Display name. Required when creating.'],
                'url_key' => [
                    'type' => 'string',
                    'description' => 'URL segment, normalised the same way the admin normalises it. '
                        . 'Derived from the name when omitted on create.',
                ],
                'description' => ['type' => 'string', 'description' => 'Shown on the tag page.'],
                'meta_title' => ['type' => 'string', 'description' => 'SEO title.'],
                'meta_description' => ['type' => 'string', 'description' => 'SEO description.'],
            ],
            'additionalProperties' => false,
        ];
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
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $tagId = $this->optionalInt($arguments, 'tag_id');
        $isNew = $tagId === null;

        if ($isNew) {
            $tag = $this->tagFactory->create();
            $tag->setData('name', $this->requireString($arguments, 'name'));
        } else {
            try {
                $tag = $this->tagRepository->getById($tagId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('No blog tag exists with tag_id %1.', $tagId)
                );
            }
        }

        $changed = $isNew ? ['name'] : [];

        foreach (['name', 'description', 'meta_title', 'meta_description'] as $key) {
            if (($isNew && $key === 'name') || !array_key_exists($key, $arguments)) {
                continue;
            }
            if ($arguments[$key] !== null && !is_string($arguments[$key])) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $tag->setData($key, $arguments[$key]);
            $changed[] = $key;
        }

        if ($isNew || array_key_exists('url_key', $arguments)) {
            $given = $arguments['url_key'] ?? null;
            if ($given !== null && !is_string($given)) {
                throw new LocalizedException(__('The "url_key" argument must be a string.'));
            }
            $normalised = $this->urlKey->normalize((string) $given, (string) $tag->getData('name'));
            if ($normalised === '') {
                throw new LocalizedException(__(
                    'A url_key could not be derived. Pass one explicitly, or give the tag a name with '
                    . 'letters or digits in it.'
                ));
            }
            $tag->setData('url_key', $normalised);
            $changed[] = 'url_key';
        }

        if (!$isNew && $changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides tag_id.')
            );
        }

        $this->tagRepository->save($tag);

        return [
            $isNew ? 'created' : 'updated' => true,
            'tool' => $this->getName(),
            'tag_id' => (int) $tag->getId(),
            'name' => $tag->getData('name'),
            'url_key' => $tag->getData('url_key'),
            'changed_fields' => $changed,
        ];
    }
}
