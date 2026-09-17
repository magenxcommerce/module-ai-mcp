<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\ResourceModel\ConsentLog\CollectionFactory;

/**
 * What visitors agreed to, and when.
 *
 * The IP address is returned, which is personal data — but it is personal data
 * whose entire purpose is being able to show, later, that a particular consent
 * came from a particular place. A consent log with the IP stripped out does not
 * demonstrate anything, which is the only reason to keep one.
 */
class SearchConsentLog extends AbstractTool
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
        return 'search_consent_log';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search the cookie consent log: who accepted which categories, from which address '
            . 'and when, newest first. This is the evidence that a given consent was given, so it '
            . 'answers "did this customer opt into marketing, and when". A null customer_id is a '
            . 'visitor who was not signed in.';
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
                    'customer_id' => ['type' => 'integer', 'description' => 'One customer\'s consents.'],
                    'ip_address' => ['type' => 'string', 'description' => 'Exact match.'],
                    'context' => [
                        'type' => 'string',
                        'description' => 'Where the consent was captured, e.g. "cookie_banner".',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest date, inclusive, in UTC as the module stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest date, inclusive.'],
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
        return 'Magenx_Gdpr::consent_log';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $customerId = $this->optionalInt($arguments, 'customer_id');
        if ($customerId !== null) {
            $collection->addFieldToFilter('customer_id', $customerId);
        }
        foreach (['ip_address', 'context'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $collection->addFieldToFilter($field, $value);
            }
        }
        $from = $this->optionalString($arguments, 'created_from');
        if ($from !== null) {
            $collection->addFieldToFilter('created_at', ['gteq' => $from]);
        }
        $to = $this->optionalString($arguments, 'created_to');
        if ($to !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $to]);
        }

        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $row) {
            $items[] = [
                'log_id' => (int) $row->getId(),
                'customer_id' => $row->getData('customer_id') === null
                    ? null
                    : (int) $row->getData('customer_id'),
                'ip_address' => $row->getData('ip_address'),
                'context' => $row->getData('context'),
                'necessary' => (bool) $row->getData('necessary'),
                'analytics' => (bool) $row->getData('analytics'),
                'marketing' => (bool) $row->getData('marketing'),
                'preferences' => (bool) $row->getData('preferences'),
                'created_at' => $row->getData('created_at'),
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
