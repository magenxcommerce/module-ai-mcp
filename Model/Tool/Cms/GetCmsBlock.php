<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one CMS block in full, content included.
 */
class GetCmsBlock extends AbstractTool
{
    /**
     * @param BlockLocator $locator
     */
    public function __construct(
        private readonly BlockLocator $locator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_cms_block';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one CMS block by identifier, including its HTML content and the store views '
            . 'it is assigned to. list_cms_blocks is the way to find an identifier; this is the '
            . 'way to read one block before changing it. An identifier shared by several blocks on '
            . 'different store views is an error naming the block_ids rather than an arbitrary '
            . 'pick.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties('read'),
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
    public function execute(array $arguments): array
    {
        $block = $this->locator->locate(
            $this->requireString($arguments, 'identifier'),
            $this->optionalInt($arguments, 'block_id'),
            'read'
        );

        return [
            'block_id' => (int) $block->getId(),
            'identifier' => $block->getIdentifier(),
            'title' => $block->getTitle(),
            'is_active' => (bool) $block->isActive(),
            'content' => $block->getContent(),
            'creation_time' => $block->getCreationTime(),
            'update_time' => $block->getUpdateTime(),
        ];
    }
}
