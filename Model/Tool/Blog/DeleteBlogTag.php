<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Blog\Model\TagRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove a :tag:blog tag.
 *
 * The posts filed under it are not deleted — only the link between them is, so
 * nothing written is lost. What goes is the storefront page the :tag:blog tag
 * had, and any navigation pointing at it.
 */
class DeleteBlogTag extends AbstractTool
{
    /**
     * @param TagRepository $repository
     */
    public function __construct(
        private readonly TagRepository $repository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_blog_tag';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a :tag:blog tag. Posts filed under it keep their content and simply lose the '
            . 'link, so nothing written is lost — but the :tag:blog tag\'s own storefront page goes, '
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
                'tag_id' => [
                    'type' => 'integer',
                    'description' => 'The :tag:blog tag to delete; list_blog_tags reports the ids.',
                ],
            ],
            'required' => ['tag_id'],
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
        $id = $this->requireInt($arguments, 'tag_id');

        try {
            $entity = $this->repository->getById($id);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No :tag:blog tag exists with tag_id %1.', $id));
        }

        $name = (string) $entity->getData('name');
        $this->repository->delete($entity);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'tag_id' => $id,
            'name' => $name,
        ];
    }
}
