<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Shared search behaviour for the three documents an order produces.
 *
 * Invoices, shipments and credit memos are queried the same way — by order, by
 * their own increment id, by date — and differ only in which repository answers
 * and which fields are worth returning. Those two things are what a subclass
 * supplies.
 */
abstract class AbstractDocumentSearch extends AbstractTool
{
    /**
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * Ask this document's repository for a page of results.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    abstract protected function searchDocuments(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Reduce one document to the fields worth sending to a model.
     *
     * @param object $document
     * @return array<string, mixed>
     */
    abstract protected function projectDocument(object $document): array;

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'order_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to one order, by its numeric order entity id. '
                            . 'get_order and search_orders both report it as order_id.',
                    ],
                    'increment_id' => [
                        'type' => 'string',
                        'description' => 'This document\'s own increment id, which is not the '
                            . 'order\'s. Exact match.',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest creation date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as Magento stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest creation date, inclusive.'],
                    'sort_direction' => [
                        'type' => 'string',
                        'enum' => ['ASC', 'DESC'],
                        'description' => 'By creation date. Defaults to DESC, newest first.',
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
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $orderId = $this->optionalInt($arguments, 'order_id');
        if ($orderId !== null) {
            $this->searchCriteriaBuilder->addFilter('order_id', $orderId);
        }
        $incrementId = $this->optionalString($arguments, 'increment_id');
        if ($incrementId !== null) {
            $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId);
        }
        $createdFrom = $this->optionalString($arguments, 'created_from');
        if ($createdFrom !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdFrom, 'gteq');
        }
        $createdTo = $this->optionalString($arguments, 'created_to');
        if ($createdTo !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $createdTo, 'lteq');
        }

        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField('created_at')
                ->setDirection($direction === 'ASC' ? 'ASC' : 'DESC')
                ->create()
        );

        $result = $this->searchDocuments($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                fn (object $document): array => $this->projectDocument($document),
                array_values($result->getItems())
            ),
        ];
    }

    /**
     * Magento returns monetary columns as strings; a model reads a number more
     * reliably than "15.0000", and null must survive as null rather than 0.0.
     *
     * @param string|float|int|null $value
     * @return float|null
     */
    protected function money(string|float|int|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
