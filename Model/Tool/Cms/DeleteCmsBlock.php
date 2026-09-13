<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Delete a CMS block.
 */
class DeleteCmsBlock extends AbstractTool
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
        return 'delete_cms_block';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a CMS block. This cannot be undone, and anything still '
            . 'referencing the identifier — a page body, a widget, a layout handle — renders '
            . 'nothing where the block was. To take a block down reversibly, set is_active false '
            . 'with update_cms_block instead.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identifier' => ['type' => 'string', 'description' => 'Identifier of the block to delete.'],
                'block_id' => [
                    'type' => 'integer',
                    'description' => 'Only needed when one identifier is shared by several blocks '
                        . 'on different store views, which the tool will tell you about rather '
                        . 'than guessing.',
                ],
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
        $blockId = $this->optionalInt($arguments, 'block_id');

        $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        if ($blockId !== null) {
            $this->searchCriteriaBuilder->addFilter('block_id', $blockId);
        }
        $matches = array_values($this->blockRepository->getList($this->searchCriteriaBuilder->create())->getItems());

        if ($matches === []) {
            throw new LocalizedException($blockId === null
                ? __('No CMS block exists with identifier "%1".', $identifier)
                : __('No CMS block exists with identifier "%1" and block_id %2.', $identifier, $blockId));
        }

        // Deleting an arbitrary one of several same-named blocks is worse here
        // than when updating: there is nothing to undo.
        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The identifier "%1" matches %2 CMS blocks (block_id %3). Pass block_id to say which one to delete.',
                $identifier,
                count($matches),
                implode(', ', array_map(static fn (BlockInterface $b): string => (string) $b->getId(), $matches))
            ));
        }

        $block = $matches[0];
        $deleted = [
            'block_id' => (int) $block->getId(),
            'identifier' => $block->getIdentifier(),
            'title' => $block->getTitle(),
        ];

        $this->blockRepository->delete($block);

        return ['deleted' => true, 'tool' => $this->getName()] + $deleted;
    }
}
