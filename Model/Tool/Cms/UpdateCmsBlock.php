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
                'block_id' => [
                    'type' => 'integer',
                    'description' => 'Only needed when one identifier is shared by several blocks on '
                        . 'different store views, which the tool will tell you about rather than guessing.',
                ],
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
        $block = $this->resolveBlock($identifier, $this->optionalInt($arguments, 'block_id'));

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
            'block_id' => (int) $saved->getId(),
            'identifier' => $saved->getIdentifier(),
            'changed_fields' => $changed,
            'title' => $saved->getTitle(),
            'is_active' => (bool) $saved->isActive(),
        ];
    }

    /**
     * Find the one block this call means.
     *
     * A CMS block identifier is not unique — Magento allows the same identifier
     * on several blocks assigned to different store views. Taking the first row
     * would edit an arbitrary one of them and report success naming only the
     * identifier, leaving the agent with no way to know what it changed. So an
     * ambiguous identifier is an error that names the candidates instead.
     *
     * @param string $identifier
     * @param int|null $blockId
     * @return BlockInterface
     * @throws LocalizedException
     */
    private function resolveBlock(string $identifier, ?int $blockId): BlockInterface
    {
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

        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The identifier "%1" matches %2 CMS blocks (block_id %3). Pass block_id to say which one to change.',
                $identifier,
                count($matches),
                implode(', ', array_map(static fn (BlockInterface $b): string => (string) $b->getId(), $matches))
            ));
        }

        return $matches[0];
    }
}
