<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\Data\CategoryTreeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The category tree, as the agent's map of the catalog.
 */
class GetCategoryTree extends AbstractTool
{
    private const DEFAULT_DEPTH = 3;

    /**
     * @param CategoryManagementInterface $categoryManagement
     */
    public function __construct(
        private readonly CategoryManagementInterface $categoryManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_category_tree';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the category tree with ids, names, url keys, active state and product counts. '
            . 'Start here to find the category id another tool needs.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'root_category_id' => [
                    'type' => 'integer',
                    'description' => 'Category to start from. Defaults to the store\'s root.',
                ],
                'depth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'description' => 'How many levels to return. Defaults to ' . self::DEFAULT_DEPTH
                        . '; a deep tree is large, so raise it only when needed.',
                ],
            ],
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
        $rootId = $this->optionalInt($arguments, 'root_category_id');
        $depth = $this->optionalInt($arguments, 'depth') ?? self::DEFAULT_DEPTH;
        $depth = max(1, min($depth, 10));

        try {
            $tree = $this->categoryManagement->getTree($rootId, $depth);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No category exists with id %1.', (string) $rootId));
        }

        return ['tree' => $this->flattenNode($tree)];
    }

    /**
     * @param CategoryTreeInterface $node
     * @return array<string, mixed>
     */
    private function flattenNode(CategoryTreeInterface $node): array
    {
        $data = [
            'id' => (int) $node->getId(),
            'parent_id' => (int) $node->getParentId(),
            'name' => $node->getName(),
            'is_active' => (bool) $node->getIsActive(),
            'position' => (int) $node->getPosition(),
            'level' => (int) $node->getLevel(),
            'product_count' => (int) $node->getProductCount(),
        ];

        $children = $node->getChildrenData();
        if (is_array($children) && $children !== []) {
            $data['children'] = array_map(fn ($child): array => $this->flattenNode($child), $children);
        }

        return $data;
    }
}
