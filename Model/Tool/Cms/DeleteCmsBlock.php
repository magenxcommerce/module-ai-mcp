<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\BlockRepositoryInterface;

/**
 * Delete a CMS block.
 */
class DeleteCmsBlock extends AbstractTool
{
    /**
     * @param BlockRepositoryInterface $blockRepository
     * @param BlockLocator $locator
     */
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockLocator $locator
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

        // Deleting an arbitrary one of several same-named blocks is worse here
        // than when updating: there is nothing to undo. The locator refuses an
        // ambiguous identifier for both.
        $block = $this->locator->locate($identifier, $this->optionalInt($arguments, 'block_id'), 'delete');
        $deleted = [
            'block_id' => (int) $block->getId(),
            'identifier' => $block->getIdentifier(),
            'title' => $block->getTitle(),
        ];

        $this->blockRepository->delete($block);

        return ['deleted' => true, 'tool' => $this->getName()] + $deleted;
    }
}
