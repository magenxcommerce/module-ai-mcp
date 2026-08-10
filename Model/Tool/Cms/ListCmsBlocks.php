<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * List CMS blocks. Content is omitted unless asked for — block HTML is long.
 */
class ListCmsBlocks extends AbstractTool
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
        return 'list_cms_blocks';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List CMS blocks with their identifier, title and active state. '
            . 'Set include_content to read the block HTML, which is omitted by default because it is long.';
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
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Return only the block with this identifier.',
                    ],
                    'include_content' => [
                        'type' => 'boolean',
                        'description' => 'Include the block HTML. Default false.',
                    ],
                ],
                $this->pagingSchema()
            ),
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
        $this->searchCriteriaBuilder->setPageSize($this->pageSize($arguments));
        $this->searchCriteriaBuilder->setCurrentPage($this->currentPage($arguments));

        $identifier = $this->optionalString($arguments, 'identifier');
        if ($identifier !== null) {
            $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        }

        $includeContent = ($arguments['include_content'] ?? false) === true;
        $result = $this->blockRepository->getList($this->searchCriteriaBuilder->create());

        $items = [];
        foreach ($result->getItems() as $block) {
            $item = [
                'id' => (int) $block->getId(),
                'identifier' => $block->getIdentifier(),
                'title' => $block->getTitle(),
                'is_active' => (bool) $block->isActive(),
                'creation_time' => $block->getCreationTime(),
                'update_time' => $block->getUpdateTime(),
            ];
            if ($includeContent) {
                $item['content'] = $block->getContent();
            }
            $items[] = $item;
        }

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $this->currentPage($arguments),
            'page_size' => $this->pageSize($arguments),
            'items' => $items,
        ];
    }
}
