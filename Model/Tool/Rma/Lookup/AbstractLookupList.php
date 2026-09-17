<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma\Lookup;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;

/**
 * Shared reading for the RMA module's four lookup tables.
 *
 * Statuses, reasons, resolution types and item conditions are the same shape —
 * code, label, active flag, sort order, per-store labels — but the module gives
 * each its own interface with no common parent, so a subclass supplies the
 * repository call and this class does everything else. The entities are
 * therefore handled as plain objects here; the concrete types live in the
 * subclasses, where the repository is injected with its real interface.
 */
abstract class AbstractLookupList extends AbstractTool
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
     * What these rows are called, for the description and the result.
     *
     * @return string
     */
    abstract protected function entityName(): string;

    /**
     * Ask this lookup's repository for a page of rows.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return object Search results carrying getItems() and getTotalCount().
     */
    abstract protected function searchEntities(SearchCriteriaInterface $searchCriteria): object;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'List the RMA %s with their ids, codes and labels. The ids are what the return tools '
            . 'take, so this is how to turn one into a label or find the id to set. Inactive rows '
            . 'are included unless you filter them out.',
            $this->entityName()
        );
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
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Restrict to active or inactive rows.',
                    ],
                    'code' => ['type' => 'string', 'description' => 'Exact match on the code.'],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * Every subclass returns the shared envelope from this class's own
     * `execute()`, so the promise is made once, here, rather than copied into
     * each of them.
     *
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
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

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $this->searchCriteriaBuilder->addFilter('is_active', $isActive ? 1 : 0);
        }
        $code = $this->optionalString($arguments, 'code');
        if ($code !== null) {
            $this->searchCriteriaBuilder->addFilter('code', $code);
        }

        // Sort order is what the admin dropdowns use, so it is the order an
        // operator recognises.
        $this->searchCriteriaBuilder->addSortOrder(
            $this->sortOrderBuilder->setField('sort_order')->setDirection('ASC')->create()
        );

        $result = $this->searchEntities($this->searchCriteriaBuilder->create());

        return [
            'entity' => $this->entityName(),
            'total_count' => $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (object $row): array => [
                    'entity_id' => $row->getEntityId(),
                    'code' => $row->getCode(),
                    'label' => $row->getLabel(),
                    'is_active' => (bool) $row->getIsActive(),
                    'sort_order' => $row->getSortOrder(),
                    // Per-store overrides of the label, keyed by store id.
                    'store_labels' => $row->getStoreLabels(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
