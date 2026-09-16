<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * The data-subject request queue.
 *
 * Oldest first by default, which is the opposite of every other search here and
 * deliberate: these carry a statutory deadline, so the one that has been
 * waiting longest is the one that matters, not the one that arrived last.
 */
class SearchGdprRequests extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param RequestProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly RequestProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_gdpr_requests';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search data-subject requests by status, type, customer or date. Oldest first, '
            . 'unlike the other searches here, because these run against a statutory deadline and '
            . 'the longest-waiting request is the one that matters. Filter status to "pending" for '
            . 'the work queue. Each row carries the customer\'s current email, which is what '
            . 'approve_gdpr_request requires as confirmation.';
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
                    'status' => [
                        'type' => 'string',
                        'enum' => [
                            DsrRequest::STATUS_PENDING,
                            DsrRequest::STATUS_APPROVED,
                            DsrRequest::STATUS_DENIED,
                            DsrRequest::STATUS_COMPLETED,
                        ],
                        'description' => 'Restrict to one status. "pending" is the work queue.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => [
                            DsrRequest::TYPE_EXPORT_DATA,
                            DsrRequest::TYPE_ANONYMIZE_DATA,
                            DsrRequest::TYPE_ERASE_DATA,
                        ],
                        'description' => 'Restrict to one kind of request. Export and anonymize '
                            . 'complete themselves; erase is the one needing a decision.',
                    ],
                    'customer_id' => ['type' => 'integer', 'description' => 'One customer\'s requests.'],
                    'requested_from' => [
                        'type' => 'string',
                        'description' => 'Earliest request date, inclusive, in UTC as the module '
                            . 'stores it.',
                    ],
                    'requested_to' => ['type' => 'string', 'description' => 'Latest request date, inclusive.'],
                    'sort_direction' => [
                        'type' => 'string',
                        'enum' => ['ASC', 'DESC'],
                        'description' => 'Defaults to ASC — oldest first.',
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
        return 'Magenx_Gdpr::requests';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $this->applyEnum($collection, $arguments, 'status', [
            DsrRequest::STATUS_PENDING,
            DsrRequest::STATUS_APPROVED,
            DsrRequest::STATUS_DENIED,
            DsrRequest::STATUS_COMPLETED,
        ]);
        $this->applyEnum($collection, $arguments, 'type', [
            DsrRequest::TYPE_EXPORT_DATA,
            DsrRequest::TYPE_ANONYMIZE_DATA,
            DsrRequest::TYPE_ERASE_DATA,
        ]);

        $customerId = $this->optionalInt($arguments, 'customer_id');
        if ($customerId !== null) {
            $collection->addFieldToFilter('customer_id', $customerId);
        }

        $from = $this->optionalString($arguments, 'requested_from');
        if ($from !== null) {
            $collection->addFieldToFilter('requested_at', ['gteq' => $from]);
        }
        $to = $this->optionalString($arguments, 'requested_to');
        if ($to !== null) {
            $collection->addFieldToFilter('requested_at', ['lteq' => $to]);
        }

        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'ASC'));
        $collection->setOrder('requested_at', $direction === 'DESC' ? 'DESC' : 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $request) {
            /** @var DsrRequest $request */
            $items[] = $this->projector->toArray($request);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }

    /**
     * Refuse an unknown value rather than filter on it — an empty page would
     * read as "no such requests" instead of "no such status".
     *
     * @param object $collection
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param string[] $allowed
     * @return void
     * @throws LocalizedException
     */
    private function applyEnum(object $collection, array $arguments, string $field, array $allowed): void
    {
        $value = $this->optionalString($arguments, $field);
        if ($value === null) {
            return;
        }

        if (!in_array($value, $allowed, true)) {
            throw new LocalizedException(__(
                'Unknown "%1" value "%2". This module records: %3.',
                $field,
                $value,
                implode(', ', $allowed)
            ));
        }

        $collection->addFieldToFilter($field, $value);
    }
}
