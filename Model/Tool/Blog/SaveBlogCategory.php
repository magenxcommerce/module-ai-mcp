<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\CategoryFactory;
use Magenx\Blog\Model\CategoryRepository;
use Magenx\Blog\Model\UrlKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Create or rename a blog category.
 */
class SaveBlogCategory extends AbstractTool
{
    /**
     * @param CategoryFactory $categoryFactory
     * @param CategoryRepository $categoryRepository
     * @param UrlKey $urlKey
     */
    public function __construct(
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepository $categoryRepository,
        private readonly UrlKey $urlKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_blog_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create or update a blog category. Omit category_id to create one; pass it to '
            . 'change an existing one, in which case only the fields you pass are changed. The URL '
            . 'key is derived from the name unless you pass one, and changing it moves the '
            . 'category\'s storefront address — anything linking to the old one breaks.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'The category to change. Omit to create a new one.',
                ],
                'name' => ['type' => 'string', 'description' => 'Display name. Required when creating.'],
                'url_key' => [
                    'type' => 'string',
                    'description' => 'URL segment, normalised the same way the admin normalises it. '
                        . 'Derived from the name when omitted on create.',
                ],
                'description' => ['type' => 'string', 'description' => 'Shown on the category page.'],
                'is_active' => ['type' => 'boolean', 'description' => 'Whether it appears at all.'],
                'position' => ['type' => 'integer', 'description' => 'Sort position, lower first.'],
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
        return 'Magenx_Blog::category';
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
        $categoryId = $this->optionalInt($arguments, 'category_id');
        $isNew = $categoryId === null;

        if ($isNew) {
            $category = $this->categoryFactory->create();
            $category->setData('name', $this->requireString($arguments, 'name'));
        } else {
            try {
                $category = $this->categoryRepository->getById($categoryId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('No blog category exists with category_id %1.', $categoryId)
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
            $category->setData($key, $arguments[$key]);
            $changed[] = $key;
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(
                    __('The "is_active" argument must be true or false, not a string or a number.')
                );
            }
            $category->setData('is_active', $arguments['is_active'] ? 1 : 0);
            $changed[] = 'is_active';
        }

        $position = $this->optionalInt($arguments, 'position');
        if ($position !== null) {
            $category->setData('position', $position);
            $changed[] = 'position';
        }

        if ($isNew || array_key_exists('url_key', $arguments)) {
            $given = $arguments['url_key'] ?? null;
            if ($given !== null && !is_string($given)) {
                throw new LocalizedException(__('The "url_key" argument must be a string.'));
            }
            $normalised = $this->urlKey->normalize((string) $given, (string) $category->getData('name'));
            if ($normalised === '') {
                throw new LocalizedException(__(
                    'A url_key could not be derived. Pass one explicitly, or give the category a '
                    . 'name with letters or digits in it.'
                ));
            }
            $category->setData('url_key', $normalised);
            $changed[] = 'url_key';
        }

        if (!$isNew && $changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides category_id.')
            );
        }

        $this->categoryRepository->save($category);

        return [
            $isNew ? 'created' : 'updated' => true,
            'tool' => $this->getName(),
            'category_id' => (int) $category->getId(),
            'name' => $category->getData('name'),
            'url_key' => $category->getData('url_key'),
            'is_active' => (bool) $category->getData('is_active'),
            'position' => (int) $category->getData('position'),
            'changed_fields' => $changed,
        ];
    }
}
