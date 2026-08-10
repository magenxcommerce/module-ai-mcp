<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Category;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\CategoryLinkRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Put a product into a category, or take it out again.
 *
 * Category membership is the one product relationship an agent changes often
 * enough to deserve its own tool rather than a custom_attributes edit.
 */
class AssignProductToCategory extends AbstractTool
{
    /**
     * @param CategoryLinkRepositoryInterface $linkRepository
     * @param CategoryProductLinkInterfaceFactory $linkFactory
     */
    public function __construct(
        private readonly CategoryLinkRepositoryInterface $linkRepository,
        private readonly CategoryProductLinkInterfaceFactory $linkFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'assign_product_to_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a product to a category, or remove it, by sku and category id.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'category_id' => ['type' => 'integer'],
                'action' => [
                    'type' => 'string',
                    'enum' => ['assign', 'remove'],
                    'description' => 'Defaults to "assign".',
                ],
                'position' => ['type' => 'integer', 'description' => 'Sort position within the category.'],
            ],
            'required' => ['sku', 'category_id'],
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
        $sku = $this->requireString($arguments, 'sku');
        $categoryId = $this->requireInt($arguments, 'category_id');
        $action = $this->optionalString($arguments, 'action', 'assign');

        if ($action === 'remove') {
            $this->linkRepository->deleteByIds((string) $categoryId, $sku);

            return ['removed' => true, 'sku' => $sku, 'category_id' => $categoryId];
        }

        if ($action !== 'assign') {
            throw new LocalizedException(__('The "action" argument must be "assign" or "remove".'));
        }

        $link = $this->linkFactory->create();
        $link->setSku($sku);
        $link->setCategoryId((string) $categoryId);
        $position = $this->optionalInt($arguments, 'position');
        if ($position !== null) {
            $link->setPosition($position);
        }

        // assignProductToCategories replaces the whole set, so the single-link
        // repository is what "add one" actually means.
        $this->linkRepository->save($link);

        return ['assigned' => true, 'sku' => $sku, 'category_id' => $categoryId];
    }
}
