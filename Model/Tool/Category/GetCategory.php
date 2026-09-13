<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Read one category in full.
 *
 * get_category_tree is the map — ids, names and where things sit. This is the
 * category itself: the fields update_category writes, at the scope a given
 * store view resolves them.
 */
class GetCategory extends AbstractTool
{
    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one category by id: the fields update_category writes, plus its path and '
            . 'position in the tree. Pass store_code to see the values a store view resolves — '
            . 'without it you get the default scope, which is what every view inherits. Use '
            . 'attributes to include extra ones such as description or meta_title, which are '
            . 'omitted by default.';
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
                    'description' => 'Id of the category, as get_category_tree reports it.',
                ],
                'store_code' => $this->storeResolver->schemaProperty(),
                'attributes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Extra attribute codes to include, e.g. ["description", '
                        . '"meta_title", "display_mode"].',
                ],
            ],
            'required' => ['category_id'],
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
    public function execute(array $arguments): array
    {
        $categoryId = $this->requireInt($arguments, 'category_id');
        $storeId = $this->storeResolver->resolve($this->optionalString($arguments, 'store_code'));

        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No category exists with id %1.', $categoryId));
        }

        $detail = [
            'category_id' => (int) $category->getId(),
            'parent_id' => (int) $category->getParentId(),
            'store_id' => $storeId,
            'name' => $category->getName(),
            'is_active' => (bool) $category->getIsActive(),
            'include_in_menu' => (bool) $category->getIncludeInMenu(),
            'position' => (int) $category->getPosition(),
            'level' => (int) $category->getLevel(),
            // The materialised path, root first. move_category changes this and
            // the url of everything beneath it.
            'path' => $category->getPath(),
            'children_ids' => $this->childIds($category),
            'created_at' => $category->getCreatedAt(),
            'updated_at' => $category->getUpdatedAt(),
        ];

        foreach ($this->optionalArray($arguments, 'attributes') as $code) {
            if (!is_string($code) || $code === '') {
                continue;
            }
            $detail['custom_attributes'][$code] = $category->getCustomAttribute($code)?->getValue();
        }

        return $detail;
    }

    /**
     * Magento stores the immediate children as a comma-separated string.
     *
     * @param CategoryInterface $category
     * @return array<int, int>
     */
    private function childIds(CategoryInterface $category): array
    {
        $children = (string) $category->getChildren();
        if (trim($children) === '') {
            return [];
        }

        return array_map('intval', array_filter(array_map('trim', explode(',', $children)), 'strlen'));
    }
}
