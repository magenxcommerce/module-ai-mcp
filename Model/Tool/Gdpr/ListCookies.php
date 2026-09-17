<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\ResourceModel\Cookie\CollectionFactory;

/**
 * The declared cookie registry.
 *
 * This is the list a privacy policy is generated from and what a regulator
 * compares against what the site actually sets, so the thing that matters about
 * it is that it stays current as third-party scripts come and go — which is why
 * these, unlike the groups, are writable.
 */
class ListCookies extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_gdpr_cookies';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the declared cookies with their consent group, purpose and lifetime. This is '
            . 'the registry a cookie policy is built from, so it is what to check when a script is '
            . 'added or removed. Filter by group_id to see one consent category.';
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
                    'group_id' => [
                        'type' => 'integer',
                        'description' => 'One consent category; list_gdpr_cookie_groups reports the ids.',
                    ],
                    'is_active' => ['type' => 'boolean', 'description' => 'Restrict to active or inactive.'],
                    'source' => [
                        'type' => 'string',
                        'description' => 'Where the cookie comes from, e.g. "storefront".',
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
        return 'Magenx_Gdpr::cookies';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $groupId = $this->optionalInt($arguments, 'group_id');
        if ($groupId !== null) {
            $collection->addFieldToFilter('group_id', $groupId);
        }
        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }
        $source = $this->optionalString($arguments, 'source');
        if ($source !== null) {
            $collection->addFieldToFilter('source', $source);
        }

        $collection->setOrder('sort_order', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'cookie_id' => (int) $row->getId(),
                'group_id' => (int) $row->getData('group_id'),
                'name' => $row->getData('name'),
                'source' => $row->getData('source'),
                'purpose' => $row->getData('purpose'),
                'duration_label' => $row->getData('duration_label'),
                'is_active' => (bool) $row->getData('is_active'),
                'sort_order' => (int) $row->getData('sort_order'),
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
