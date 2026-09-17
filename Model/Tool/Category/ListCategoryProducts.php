<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryProductLinkInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * What is in a category — the inverse of assign_product_to_category.
 */
class ListCategoryProducts extends AbstractTool
{
    /**
     * @param CategoryLinkManagementInterface $categoryLinkManagement
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_category_products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the products assigned to one category, with the sort position each holds '
            . 'there. Direct assignments only: a product in a child category is not listed here, '
            . 'even though the storefront may show it under an anchor parent. Returns sku and '
            . 'position rather than whole products — pass a sku to get_product for the rest.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'category_id' => [
                        'type' => 'integer',
                        'description' => 'Id of the category, as get_category_tree reports it.',
                    ],
                ],
                $this->pagingSchema()
            ),
            'required' => ['category_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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

        // The link service answers for an id it has never seen with an empty
        // list, which reads the same as an empty category. Loading the category
        // first is what tells those two apart.
        try {
            $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No category exists with id %1.', $categoryId));
        }

        // Magento's contract has no paged form: it returns every assignment,
        // which is why these are sku and position rather than products. Paging
        // here bounds what reaches the model, not what is read.
        $links = array_values($this->categoryLinkManagement->getAssignedProducts($categoryId));
        usort(
            $links,
            static fn (CategoryProductLinkInterface $a, CategoryProductLinkInterface $b): int
                => (int) $a->getPosition() <=> (int) $b->getPosition()
        );

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        return [
            'total_count' => count($links),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (CategoryProductLinkInterface $link): array => [
                    'sku' => $link->getSku(),
                    'position' => (int) $link->getPosition(),
                ],
                array_slice($links, ($currentPage - 1) * $pageSize, $pageSize)
            ),
        ];
    }
}
