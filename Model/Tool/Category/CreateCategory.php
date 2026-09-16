<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Create a category under an existing parent.
 */
class CreateCategory extends AbstractTool
{
    /**
     * @param CategoryInterfaceFactory $categoryFactory
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a category under an existing parent. Use get_category_tree to find the parent id.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parent_id' => ['type' => 'integer', 'description' => 'Id of the parent category.'],
                'name' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean', 'description' => 'Defaults to true.'],
                'include_in_menu' => ['type' => 'boolean', 'description' => 'Defaults to true.'],
                'url_key' => ['type' => 'string', 'description' => 'Defaults to a slug of the name.'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['parent_id', 'name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::categories';
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
        // Adds a category; nothing that already exists is touched.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $parentId = $this->requireInt($arguments, 'parent_id');
        $name = $this->requireString($arguments, 'name');

        try {
            $parent = $this->categoryRepository->get($parentId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('No category exists with id %1. Use get_category_tree to find a valid parent.', $parentId)
            );
        }

        $category = $this->categoryFactory->create();
        $category->setParentId((int) $parent->getId());
        $category->setName($name);
        $category->setIsActive($this->optionalBool($arguments, 'is_active', true));
        $category->setData('include_in_menu', $this->optionalBool($arguments, 'include_in_menu', true));

        $urlKey = $this->optionalString($arguments, 'url_key');
        if ($urlKey !== null) {
            $category->setData('url_key', $urlKey);
        }
        $description = $this->optionalString($arguments, 'description');
        if ($description !== null) {
            $category->setData('description', $description);
        }

        $saved = $this->categoryRepository->save($category);

        return [
            'created' => true,
            'category_id' => (int) $saved->getId(),
            'parent_id' => (int) $saved->getParentId(),
            'name' => $saved->getName(),
            'url_key' => $saved->getData('url_key'),
        ];
    }
}
