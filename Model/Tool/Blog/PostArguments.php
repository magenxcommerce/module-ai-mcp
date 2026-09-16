<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\Blog\Model\Post;
use Magenx\Blog\Model\UrlKey;
use Magento\Framework\Exception\LocalizedException;

/**
 * The writable fields of a blog post, shared by create and update.
 *
 * Follows the `<Thing>Arguments` convention — see
 * `Model/Tool/Cms/PageContentArguments.php` — so both tools validate the same
 * way and `applyTo()` reports which fields actually moved.
 *
 * The relation fields are the interesting part. `category_ids`, `tag_ids` and
 * `store_ids` are written by the resource model's `_afterSave`, but only when
 * the model carries that key at all, so leaving one out of an update preserves
 * it rather than clearing it. That is why they are set only when passed, and
 * why passing an empty array is a real instruction to detach everything.
 */
class PostArguments
{
    /** Magento's "every store view" id, and what the admin means by no selection. */
    private const ALL_STORES = 0;

    /**
     * @param UrlKey $urlKey
     */
    public function __construct(
        private readonly UrlKey $urlKey
    ) {
    }

    /**
     * @param bool $forCreate
     * @return array<string, mixed>
     */
    public function schemaProperties(bool $forCreate): array
    {
        $required = $forCreate ? ' Required.' : '';

        return [
            'title' => ['type' => 'string', 'description' => 'Headline of the post.' . $required],
            'content' => [
                'type' => 'string',
                'description' => 'The article body, as HTML.' . $required,
            ],
            'short_description' => [
                'type' => 'string',
                'description' => 'Teaser shown in listings.',
            ],
            'url_key' => [
                'type' => 'string',
                'description' => 'URL segment. Normalised the same way the admin normalises it, so '
                    . 'what you pass is not necessarily what is stored. Derived from the title '
                    . 'when omitted on create.',
            ],
            'is_active' => [
                'type' => 'boolean',
                'description' => 'Whether the post is published. New posts default to active, so '
                    . 'pass false to draft one.',
            ],
            'publish_date' => [
                'type' => 'string',
                'description' => 'Publication date, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS", in UTC '
                    . 'as the module stores it.',
            ],
            'author_name' => ['type' => 'string', 'description' => 'Byline.'],
            'image' => [
                'type' => 'string',
                'description' => 'Path of an image already in the media gallery. This does not '
                    . 'upload anything — use upload_media_gallery_asset first.',
            ],
            'meta_title' => ['type' => 'string', 'description' => 'SEO title.'],
            'meta_description' => ['type' => 'string', 'description' => 'SEO description.'],
            'meta_keywords' => ['type' => 'string', 'description' => 'SEO keywords.'],
            'category_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Blog categories to file it under; list_blog_categories reports '
                    . 'the ids. Replaces the whole set. Omit to leave them alone; pass [] to '
                    . 'detach all.',
            ],
            'tag_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Tags to apply; list_blog_tags reports the ids. Replaces the whole '
                    . 'set. Omit to leave them alone; pass [] to detach all.',
            ],
            'store_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Store views the post appears on; list_stores reports the ids. '
                    . 'Use [0] for every store view, which is the default for a new post. An empty '
                    . 'array is refused: it would hide the post from the storefront entirely while '
                    . 'leaving it visible in the admin.',
            ],
        ];
    }

    /**
     * @param Post $post
     * @param array<string, mixed> $arguments
     * @param bool $isNew
     * @return string[] The fields that changed.
     * @throws LocalizedException
     */
    public function applyTo(Post $post, array $arguments, bool $isNew): array
    {
        $changed = [];

        if ($isNew) {
            $post->setData('title', $this->requireString($arguments, 'title'));
            $post->setData('content', $this->requireString($arguments, 'content'));
            $changed[] = 'title';
            $changed[] = 'content';
        }

        $strings = ['title', 'content', 'short_description', 'author_name', 'image',
            'meta_title', 'meta_description', 'meta_keywords', 'publish_date'];
        foreach ($strings as $key) {
            if (($isNew && in_array($key, ['title', 'content'], true)) || !array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ($value !== null && !is_string($value)) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $post->setData($key, $value);
            $changed[] = $key;
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(
                    __('The "is_active" argument must be true or false, not a string or a number.')
                );
            }
            $post->setData('is_active', $arguments['is_active'] ? 1 : 0);
            $changed[] = 'is_active';
        }

        $this->applyUrlKey($post, $arguments, $isNew, $changed);
        $this->applyRelations($post, $arguments, $isNew, $changed);

        return $changed;
    }

    /**
     * @param Post $post
     * @param array<string, mixed> $arguments
     * @param bool $isNew
     * @param string[] $changed
     * @return void
     * @throws LocalizedException
     */
    private function applyUrlKey(Post $post, array $arguments, bool $isNew, array &$changed): void
    {
        $given = $arguments['url_key'] ?? null;
        if ($given === null && !$isNew) {
            return;
        }
        if ($given !== null && !is_string($given)) {
            throw new LocalizedException(__('The "url_key" argument must be a string.'));
        }

        // Normalised through the module's own helper so a key written here and
        // a key written in the admin cannot end up in different shapes.
        $normalised = $this->urlKey->normalize((string) $given, (string) $post->getData('title'));
        if ($normalised === '') {
            throw new LocalizedException(__(
                'A url_key could not be derived. Pass one explicitly, or give the post a title '
                . 'with letters or digits in it.'
            ));
        }

        $post->setData('url_key', $normalised);
        $changed[] = 'url_key';
    }

    /**
     * @param Post $post
     * @param array<string, mixed> $arguments
     * @param bool $isNew
     * @param string[] $changed
     * @return void
     * @throws LocalizedException
     */
    private function applyRelations(Post $post, array $arguments, bool $isNew, array &$changed): void
    {
        foreach (['category_ids', 'tag_ids'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $post->setData($key, $this->intList($arguments[$key], $key));
            $changed[] = $key;
        }

        if (array_key_exists('store_ids', $arguments)) {
            $storeIds = $this->intList($arguments['store_ids'], 'store_ids');
            if ($storeIds === []) {
                // The admin treats an empty selection as every store view. Here
                // it would be an explicit instruction, and honouring it writes
                // no rows at all — which the storefront's inner join reads as
                // "does not exist" while the admin grid still lists the post.
                throw new LocalizedException(__(
                    'An empty "store_ids" would hide the post from every storefront while leaving '
                    . 'it in the admin. Pass [0] for all store views, or is_active false to '
                    . 'unpublish it.'
                ));
            }
            $post->setData('store_ids', $storeIds);
            $changed[] = 'store_ids';
        } elseif ($isNew) {
            $post->setData('store_ids', [self::ALL_STORES]);
        }
    }

    /**
     * @param mixed $value
     * @param string $key
     * @return int[]
     * @throws LocalizedException
     */
    private function intList(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new LocalizedException(__('The "%1" argument must be an array of ids.', $key));
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new LocalizedException(__('Every id in "%1" must be a whole number.', $key));
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    private function requireString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new LocalizedException(__('The "%1" argument is required.', $key));
        }

        return $value;
    }
}
