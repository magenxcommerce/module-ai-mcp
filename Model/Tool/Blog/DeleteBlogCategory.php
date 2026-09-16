<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\CategoryRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove a :category:blog category.
 *
 * The posts filed under it are not deleted — only the link between them is, so
 * nothing written is lost. What goes is the storefront page the :category:blog category
 * had, and any navigation pointing at it.
 */
class DeleteBlogCategory extends AbstractTool
{
    /**
     * @param CategoryRepository $repository
     */
    public function __construct(
        private readonly CategoryRepository $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_blog_category';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a :category:blog category. Posts filed under it keep their content and simply lose the '
            . 'link, so nothing written is lost — but the :category:blog category\'s own storefront page goes, '
            . 'along with anything linking to it.';
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
                    'description' => 'The :category:blog category to delete; list_blog_categorys reports the ids.',
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
        return 'Magenx_Blog';
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
        $id = $this->requireInt($arguments, 'category_id');

        try {
            $entity = $this->repository->getById($id);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No :category:blog category exists with category_id %1.', $id));
        }

        $name = (string) $entity->getData('name');
        $this->repository->delete($entity);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'category_id' => $id,
            'name' => $name,
        ];
    }
}
