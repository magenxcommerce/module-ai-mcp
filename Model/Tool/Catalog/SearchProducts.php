<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Find products by sku, name or attribute.
 */
class SearchProducts extends AbstractTool
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param FilterGroupBuilder $filterGroupBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ProductProjector $projector
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly ProductProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search the product catalog by free text (matched against sku and name) and/or exact '
            . 'attribute filters such as status, type_id or attribute_set_id. Returns a compact '
            . 'summary per product; use get_product for the full record.';
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
                        'description' => 'Free text matched as a substring against sku and name.',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Exact-match attribute filters, e.g. '
                            . '{"status": 1, "type_id": "simple"}.',
                        'additionalProperties' => ['type' => ['string', 'number', 'boolean']],
                    ],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Attribute to sort by, e.g. "updated_at" or "price". Defaults to entity_id.',
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
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::products';
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
            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->setField('sku')->setConditionType('like')->setValue('%' . $query . '%')->create()
            );
            $this->filterGroupBuilder->addFilter(
                $this->filterBuilder->setField('name')->setConditionType('like')->setValue('%' . $query . '%')->create()
            );
            $this->searchCriteriaBuilder->setFilterGroups([$this->filterGroupBuilder->create()]);
        }

        foreach ($this->optionalArray($arguments, 'filters') as $field => $value) {
            if (!is_string($field) || is_array($value)) {
                continue;
            }
            $this->searchCriteriaBuilder->addFilter($field, $value);
        }

        $sortBy = $this->optionalString($arguments, 'sort_by');
        if ($sortBy !== null) {
            $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'ASC'));
            $this->searchCriteriaBuilder->addSortOrder(
                $this->sortOrderBuilder->setField($sortBy)
                    ->setDirection($direction === 'DESC' ? 'DESC' : 'ASC')
                    ->create()
            );
        }

        // Search runs in the default (admin) scope, which is the right basis for
        // management: it sees every product regardless of store-view overrides.
        // Read a specific store view's values with get_product.
        $result = $this->productRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn ($product): array => $this->projector->toSummary($product),
                $result->getItems()
            ),
        ];
    }
}
