<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;

/**
 * Create a CMS block.
 */
class CreateCmsBlock extends AbstractTool
{
    /**
     * @param BlockRepositoryInterface $blockRepository
     * @param BlockInterfaceFactory $blockFactory
     */
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_cms_block';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a CMS block. A block appears on the storefront only where something '
            . 'references its identifier — a widget, a page body, or a layout handle — so creating '
            . 'one changes nothing on its own. Identifiers are not unique across store views, so '
            . 'check with list_cms_blocks before reusing one.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identifier' => [
                    'type' => 'string',
                    'description' => 'The identifier other content references this block by.',
                ],
                'title' => ['type' => 'string', 'description' => 'Admin-facing name for the block.'],
                'content' => ['type' => 'string', 'description' => 'The block HTML.'],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether the block renders. Defaults to true.',
                ],
            ],
            'required' => ['identifier', 'title'],
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
        $block = $this->blockFactory->create();
        $block->setIdentifier($this->requireString($arguments, 'identifier'));
        $block->setTitle($this->requireString($arguments, 'title'));
        $block->setContent((string) $this->optionalString($arguments, 'content', ''));
        $block->setIsActive((bool) $this->optionalBool($arguments, 'is_active', true));

        $saved = $this->blockRepository->save($block);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'block_id' => (int) $saved->getId(),
            'identifier' => $saved->getIdentifier(),
            'title' => $saved->getTitle(),
            'is_active' => (bool) $saved->isActive(),
        ];
    }
}
