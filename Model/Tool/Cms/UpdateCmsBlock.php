<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change a CMS block's title, content or active state.
 */
class UpdateCmsBlock extends AbstractTool
{
    /**
     * @param BlockRepositoryInterface $blockRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_cms_block';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update a CMS block by identifier: its title, HTML content, or active state. '
            . 'Only the fields you pass are changed.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identifier' => ['type' => 'string', 'description' => 'Identifier of the block to change.'],
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string', 'description' => 'The block HTML, replaced wholesale.'],
                'is_active' => ['type' => 'boolean'],
            ],
            'required' => ['identifier'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Cms::block';
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
        $identifier = $this->requireString($arguments, 'identifier');

        $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        $matches = $this->blockRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        $block = reset($matches);
        if ($block === false) {
            throw new LocalizedException(__('No CMS block exists with identifier "%1".', $identifier));
        }

        $changed = [];
        if (array_key_exists('title', $arguments)) {
            $block->setTitle((string) $arguments['title']);
            $changed[] = 'title';
        }
        if (array_key_exists('content', $arguments)) {
            $block->setContent((string) $arguments['content']);
            $changed[] = 'content';
        }
        if (array_key_exists('is_active', $arguments)) {
            $block->setIsActive((bool) $arguments['is_active']);
            $changed[] = 'is_active';
        }

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass title, content or is_active.'));
        }

        $saved = $this->blockRepository->save($block);

        return [
            'updated' => true,
            'identifier' => $saved->getIdentifier(),
            'changed_fields' => $changed,
            'title' => $saved->getTitle(),
            'is_active' => (bool) $saved->isActive(),
        ];
    }
}
