<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

/**
 * Category names for a report's ids, in one collection load.
 *
 * Read through the collection rather than joined in the report's SQL: the
 * name is an EAV attribute, and the collection already knows how this store
 * keeps those, where a hand-written join would have to guess.
 */
class CategoryNames
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int[] $ids
     * @return array<int, string> Keyed by category id; an id with no name is absent.
     */
    public function forIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('name');
        $collection->addIdFilter($ids);

        $names = [];
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
        }

        return $names;
    }
}
