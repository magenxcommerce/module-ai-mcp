<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change an existing category. A patch, like update_product.
 */
class UpdateCategory extends AbstractTool
{
    private const SIMPLE_FIELDS = [
        'name',
        'is_active',
        'include_in_menu',
        'description',
        'url_key',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'position',
        'display_mode',
        'landing_page',
    ];

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
        return 'update_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update fields on an existing category. Only the fields you pass are changed. '
            . 'Pass store_code to set a store-view specific override.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $fields = [];
        foreach (self::SIMPLE_FIELDS as $field) {
            $fields[$field] = ['type' => ['string', 'number', 'boolean', 'null']];
        }

        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'category_id' => ['type' => 'integer', 'description' => 'Id of the category to change.'],
                    'store_code' => $this->storeResolver->schemaProperty(),
                ],
                $fields
            ),
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
    public function isWrite(): bool
    {
        return true;
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

        $changed = [];
        foreach (self::SIMPLE_FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $category->setData($field, $arguments[$field]);
            $changed[] = $field;
        }

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides category_id.'));
        }

        $category->setStoreId($storeId);
        $saved = $this->categoryRepository->save($category);

        return [
            'updated' => true,
            'category_id' => (int) $saved->getId(),
            'store_id' => $storeId,
            'changed_fields' => $changed,
            'name' => $saved->getName(),
            'is_active' => (bool) $saved->getIsActive(),
        ];
    }
}
