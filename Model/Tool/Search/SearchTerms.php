<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Search;

use Magenx\AiMcp\Model\StoreResolver;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory;

/**
 * What shoppers searched the storefront for — by default, what they did not
 * find.
 *
 * Read from Magento's own search term log, `search_query`, which keeps one
 * row per term and store with a running popularity count and the result count
 * of the latest search. It keeps no history, so there is no period: the count
 * is since the term was first searched, and `updated_at` is when it was last.
 */
class SearchTerms extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreResolver $storeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_terms';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Storefront search terms, most searched first — by default only those whose latest '
            . 'search found nothing, which are the gaps in the catalogue or its synonyms. Each term '
            . 'reports how many times it was searched, its last result count, its store and when it '
            . 'was last searched. Magento keeps running counts, not history, so there is no period.';
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
                    'zero_results_only' => [
                        'type' => 'boolean',
                        'description' => 'Only terms whose latest search returned nothing. Defaults to '
                            . 'true; false lists every term.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Only terms containing this text.',
                    ],
                    'store_code' => [
                        'type' => 'string',
                        'description' => 'Only terms searched on this store view. Call list_stores '
                            . 'for the codes.',
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
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Search::search';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        if ($this->optionalBool($arguments, 'zero_results_only', true)) {
            $collection->addFieldToFilter('num_results', 0);
        }

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            $collection->addFieldToFilter('query_text', ['like' => '%' . $query . '%']);
        }

        $storeCode = $this->optionalString($arguments, 'store_code');
        if ($storeCode !== null) {
            $collection->addFieldToFilter('store_id', $this->storeResolver->resolve($storeCode));
        }

        $collection->setOrder('popularity', 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $term) {
            $items[] = [
                'query_id' => (int) $term->getData('query_id'),
                'query_text' => $term->getData('query_text'),
                'popularity' => (int) $term->getData('popularity'),
                'num_results' => (int) $term->getData('num_results'),
                'store_id' => (int) $term->getData('store_id'),
                'redirect' => $term->getData('redirect') ?: null,
                'updated_at' => $term->getData('updated_at'),
            ];
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
