<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Find CMS pages.
 */
class SearchCmsPages extends AbstractTool
{
    /**
     * @param PageRepositoryInterface $pageRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param PageProjector $projector
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly PageProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_cms_pages';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search CMS pages by identifier, title or active state. Never returns page bodies, '
            . 'which are large; use get_cms_page with include_content to read one.';
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
                    'query' => [
                        'type' => 'string',
                        'description' => 'Free text matched as a substring against identifier and title.',
                    ],
                    'identifier' => ['type' => 'string', 'description' => 'Exact match on the url key.'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Restrict to enabled or disabled pages.'],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Field to sort by. Defaults to identifier.',
                    ],
                    'sort_direction' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
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
        return 'Magento_Cms::page';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            // One group, two filters: OR within the group.
            foreach (['identifier', 'title'] as $field) {
                $this->filterGroupBuilder->addFilter(
                    $this->filterBuilder->setField($field)
                        ->setConditionType('like')->setValue('%' . $query . '%')->create()
                );
            }
            $this->searchCriteriaBuilder->setFilterGroups([$this->filterGroupBuilder->create()]);
        }

        $identifier = $this->optionalString($arguments, 'identifier');
        if ($identifier !== null) {
            $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $this->searchCriteriaBuilder->addFilter('is_active', $isActive ? 1 : 0);
        }

        $sortBy = $this->optionalString($arguments, 'sort_by', 'identifier');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'ASC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField((string) $sortBy)
                ->setDirection($direction === 'DESC' ? 'DESC' : 'ASC')
                ->create()
        );

        $result = $this->pageRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($page): array => $this->projector->toSummary($page),
                array_values($result->getItems())
            ),
        ];
    }
}
