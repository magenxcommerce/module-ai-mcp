<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Move a category to a different parent, or reorder it under its current one.
 */
class MoveCategory extends AbstractTool
{
    /**
     * @param CategoryManagementInterface $categoryManagement
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly CategoryManagementInterface $categoryManagement,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'move_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move a category under a different parent, or reorder it under its current one by '
            . 'passing the same parent with a different after_id. The category takes its whole '
            . 'subtree and its products with it. Moving it changes the URL of everything beneath '
            . 'it, so old links stop resolving unless a redirect is configured. A category cannot '
            . 'be moved into its own subtree, and Magento refuses that.';
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
                    'description' => 'The category to move.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'The category it should sit under. Pass its current parent to '
                        . 'reorder rather than move.',
                ],
                'after_id' => [
                    'type' => 'integer',
                    'description' => 'Place it directly after this sibling. Omit to put it first '
                        . 'among the parent\'s children.',
                ],
            ],
            'required' => ['category_id', 'parent_id'],
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
        $parentId = $this->requireInt($arguments, 'parent_id');
        $afterId = $this->optionalInt($arguments, 'after_id');

        // Both ends are checked here so a wrong id is a clear error rather than
        // Magento's generic move failure.
        $this->assertExists($categoryId, 'category_id');
        $this->assertExists($parentId, 'parent_id');

        if ($this->categoryManagement->move($categoryId, $parentId, $afterId) !== true) {
            throw new LocalizedException(__(
                'Magento did not move category %1 under %2. A category cannot be moved into its '
                . 'own subtree.',
                $categoryId,
                $parentId
            ));
        }

        $moved = $this->categoryRepository->get($categoryId);

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'category_id' => $categoryId,
            'name' => $moved->getName(),
            'parent_id' => (int) $moved->getParentId(),
            'path' => $moved->getPath(),
            'level' => $moved->getLevel() === null ? null : (int) $moved->getLevel(),
            'after_id' => $afterId,
            'reindex_required' => true,
        ];
    }

    /**
     * @param int $categoryId
     * @param string $argument
     * @return void
     * @throws LocalizedException
     */
    private function assertExists(int $categoryId, string $argument): void
    {
        try {
            $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No category exists with %1 %2. get_category_tree shows the ids.',
                $argument,
                $categoryId
            ));
        }
    }
}
