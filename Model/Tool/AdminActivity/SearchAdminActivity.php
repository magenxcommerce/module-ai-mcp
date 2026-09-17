<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\AdminActivity;

use Magenx\AdminActivity\Model\Activity;
use Magenx\AdminActivity\Model\Activity\ActionType;
use Magenx\AdminActivity\Model\Config;
use Magenx\AdminActivity\Model\ResourceModel\Activity\CollectionFactory;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Find admin actions.
 *
 * This is the other half of `read_audit_log`, and the two answer different
 * questions. That tool reads this server's own file and sees only writes made
 * *through* MCP. This one reads what admin users did in the admin, which is
 * where almost every change to a store actually comes from.
 *
 * Reads through the module's collection: it has no service-contract layer, and
 * the collection is what its own grid uses.
 */
class SearchAdminActivity extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param ActivityProjector $projector
     * @param Config $config
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ActivityProjector $projector,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_admin_activity';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search the admin activity log — what admin users added, edited, deleted or viewed, '
            . 'plus logins and failed logins, newest first. This is how to answer "who changed '
            . 'this, and when": read_audit_log only sees writes made through this MCP server, not '
            . 'work done in the admin. Field-level before and after values are not returned here; '
            . 'get_admin_activity reads them for one action.';
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
                    'username' => [
                        'type' => 'string',
                        'description' => 'Exact admin username. search_admin_users reports them.',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'Admin user id. Null on rows whose user was later deleted '
                            . '— filter by username to find those.',
                    ],
                    'action_type' => [
                        'type' => 'string',
                        'enum' => [
                            ActionType::ADD,
                            ActionType::EDIT,
                            ActionType::DELETE,
                            ActionType::VIEW,
                            ActionType::PRINT_ACTION,
                            ActionType::MASS_UPDATE,
                            ActionType::LOGIN,
                            ActionType::LOGIN_FAILED,
                            ActionType::LOGOUT,
                            ActionType::PAGE_VISIT,
                        ],
                        'description' => 'Restrict to one kind of action.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => [ActionType::STATUS_SUCCESS, ActionType::STATUS_FAILURE],
                    ],
                    'entity_type' => [
                        'type' => 'string',
                        'description' => 'Model class of the affected entity, e.g. '
                            . '"Magento\\\\Catalog\\\\Model\\\\Product". Matched as a substring, so '
                            . '"Product" works.',
                    ],
                    'entity_id' => [
                        'type' => 'string',
                        'description' => 'Id of the affected entity. A string, because not every '
                            . 'tracked entity keys on an integer.',
                    ],
                    'ip_address' => ['type' => 'string', 'description' => 'Exact match.'],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as the module stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest date, inclusive.'],
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
        return 'Magenx_AdminActivity::activity';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();
        $this->applyFilters($collection, $arguments);

        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $collection->setOrder('created_at', $direction === 'ASC' ? 'ASC' : 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $activity) {
            /** @var Activity $activity */
            $items[] = $this->projector->toSummary($activity);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            // An empty result means something different depending on this, and
            // an agent that cannot tell them apart will report "nobody touched
            // it" when the truth is "nothing has been recorded since it was
            // switched off".
            'logging_enabled' => $this->config->isEnabled(),
            'items' => $items,
        ];
    }

    /**
     * @param object $collection
     * @param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    private function applyFilters(object $collection, array $arguments): void
    {
        foreach (['username', 'ip_address', 'entity_id'] as $field) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $collection->addFieldToFilter($field, $value);
            }
        }

        $userId = $this->optionalInt($arguments, 'user_id');
        if ($userId !== null) {
            $collection->addFieldToFilter('user_id', $userId);
        }

        $entityType = $this->optionalString($arguments, 'entity_type');
        if ($entityType !== null) {
            $collection->addFieldToFilter('entity_type', ['like' => '%' . $entityType . '%']);
        }

        $this->applyEnumFilter($collection, $arguments, 'action_type', [
            ActionType::ADD,
            ActionType::EDIT,
            ActionType::DELETE,
            ActionType::VIEW,
            ActionType::PRINT_ACTION,
            ActionType::MASS_UPDATE,
            ActionType::LOGIN,
            ActionType::LOGIN_FAILED,
            ActionType::LOGOUT,
            ActionType::PAGE_VISIT,
        ]);
        $this->applyEnumFilter($collection, $arguments, 'status', [
            ActionType::STATUS_SUCCESS,
            ActionType::STATUS_FAILURE,
        ]);

        $createdFrom = $this->optionalString($arguments, 'created_from');
        if ($createdFrom !== null) {
            $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        }
        $createdTo = $this->optionalString($arguments, 'created_to');
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
    }

    /**
     * Refuse an unknown value rather than filter on it.
     *
     * Silently passing it through would return an empty page, which an agent
     * reads as "this never happened" rather than "you asked for a status that
     * does not exist".
     *
     * @param object $collection
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param string[] $allowed
     * @return void
     * @throws LocalizedException
     */
    private function applyEnumFilter(object $collection, array $arguments, string $field, array $allowed): void
    {
        $value = $this->optionalString($arguments, $field);
        if ($value === null) {
            return;
        }

        if (!in_array($value, $allowed, true)) {
            throw new LocalizedException(__(
                'Unknown "%1" value "%2". This log records: %3.',
                $field,
                $value,
                implode(', ', $allowed)
            ));
        }

        $collection->addFieldToFilter($field, $value);
    }
}
