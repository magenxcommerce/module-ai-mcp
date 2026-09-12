<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryTreeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;

/**
 * Delete a category.
 *
 * Magento deletes a category's whole subtree with it, and refuses only the root
 * of a store. That makes a wrong id here unusually expensive, so the tool
 * reports what the subtree contains before removing it and names the count in
 * the result and the audit log.
 */
class DeleteCategory extends AbstractTool
{
    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryManagementInterface $categoryManagement
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryManagementInterface $categoryManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a category and every category beneath it. This cannot be '
            . 'undone. Products in those categories are not deleted, but they lose the '
            . 'assignment, so anything relying on the category — layered navigation, a menu '
            . 'entry, a URL — stops working. Call get_category_tree first: the id you pass takes '
            . 'its whole subtree with it. A store\'s root category is refused. To hide a category '
            . 'reversibly, set is_active false with update_category.';
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
                    'description' => 'Id of the category to delete, as get_category_tree reports it.',
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

        try {
            $category = $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No category exists with id %1.', $categoryId));
        }

        $deleted = [
            'category_id' => (int) $category->getId(),
            'name' => $category->getName(),
            'path' => $category->getPath(),
            'level' => $category->getLevel() === null ? null : (int) $category->getLevel(),
            // Everything below it goes too, so the count is part of the record
            // of what this call did.
            'descendants_deleted' => $this->countDescendants($categoryId),
        ];

        try {
            $this->categoryRepository->delete($category);
        } catch (StateException $e) {
            // Raised for a store's root category, which cannot be removed.
            throw new LocalizedException(__($e->getMessage()));
        }

        return ['deleted' => true, 'tool' => $this->getName(), 'reindex_required' => true] + $deleted;
    }

    /**
     * How many categories sit below this one.
     *
     * Counted through the tree contract rather than the concrete category
     * model's getAllChildren(), which is not part of CategoryInterface.
     *
     * @param int $categoryId
     * @return int
     */
    private function countDescendants(int $categoryId): int
    {
        try {
            return $this->countNodes($this->categoryManagement->getTree($categoryId)) - 1;
        } catch (\Throwable) {
            // The count is for the record, not for the decision to delete, so
            // a tree that cannot be read must not block the call.
            return 0;
        }
    }

    /**
     * @param CategoryTreeInterface $node
     * @return int
     */
    private function countNodes(CategoryTreeInterface $node): int
    {
        $count = 1;
        foreach ($node->getChildrenData() ?? [] as $child) {
            $count += $this->countNodes($child);
        }

        return $count;
    }
}
