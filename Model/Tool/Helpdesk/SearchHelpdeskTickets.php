<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Helpdesk\Model\ResourceModel\Ticket\CollectionFactory;
use Magenx\Helpdesk\Model\Ticket;
use Magento\Framework\Exception\LocalizedException;

/**
 * Find tickets.
 *
 * Reads through the module's collection rather than a repository: the help desk
 * module has no service-contract layer, and its collection is what the admin
 * grid uses. Every write in this domain still goes through TicketManager, which
 * is the module's single write path.
 */
class SearchHelpdeskTickets extends AbstractTool
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param TicketProjector $projector
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly TicketProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_helpdesk_tickets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search help desk tickets by status, department, priority, assignee, customer or '
            . 'date, newest first. Defaults to the inbox — pass folder to look in archive or spam. '
            . 'Message bodies are never returned here; list_helpdesk_ticket_messages reads the '
            . 'conversation of one ticket.';
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
                        'description' => 'Free text matched as a substring against subject, code, '
                            . 'customer_email and customer_name.',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'enum' => ['inbox', 'archive', 'spam', 'any'],
                        'description' => 'Which folder to look in. Defaults to "inbox"; "any" '
                            . 'searches all three.',
                    ],
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'list_helpdesk_statuses reports the ids.',
                    ],
                    'department_id' => [
                        'type' => 'integer',
                        'description' => 'list_helpdesk_departments reports the ids.',
                    ],
                    'priority_id' => [
                        'type' => 'integer',
                        'description' => 'list_helpdesk_priorities reports the ids.',
                    ],
                    'assigned_user_id' => [
                        'type' => 'integer',
                        'description' => 'Restrict to tickets assigned to one admin user.',
                    ],
                    'unassigned' => [
                        'type' => 'boolean',
                        'description' => 'True returns only tickets with no assignee, which is the '
                            . 'usual triage question.',
                    ],
                    'customer_email' => ['type' => 'string', 'description' => 'Exact match.'],
                    'customer_id' => ['type' => 'integer'],
                    'order_id' => ['type' => 'integer'],
                    'store_id' => ['type' => 'integer'],
                    'channel' => [
                        'type' => 'string',
                        'enum' => ['customer_account', 'email', 'contact_form', 'backend'],
                        'description' => 'How the ticket arrived.',
                    ],
                    'is_locked' => [
                        'type' => 'boolean',
                        'description' => 'True returns only tickets the customer can no longer '
                            . 'reply to.',
                    ],
                    'created_from' => [
                        'type' => 'string',
                        'description' => 'Earliest creation date, inclusive. "2026-01-01" or '
                            . '"2026-01-01 09:30:00", interpreted in UTC as the module stores it.',
                    ],
                    'created_to' => ['type' => 'string', 'description' => 'Latest creation date, inclusive.'],
                    'sort_by' => [
                        'type' => 'string',
                        'description' => 'Column to sort by, e.g. "last_reply_at". Defaults to '
                            . 'created_at.',
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
        return 'Magenx_Helpdesk::ticket';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        $collection = $this->collectionFactory->create();

        $query = $this->optionalString($arguments, 'query');
        if ($query !== null) {
            $like = ['like' => '%' . $query . '%'];
            $collection->addFieldToFilter(
                ['subject', 'code', 'customer_email', 'customer_name'],
                [$like, $like, $like, $like]
            );
        }

        // Archive and spam are deliberately out of the default view: a search
        // that silently included spam would report resolved noise as open work.
        $folder = strtolower((string) $this->optionalString($arguments, 'folder', Ticket::FOLDER_INBOX));
        if (!in_array($folder, [Ticket::FOLDER_INBOX, Ticket::FOLDER_ARCHIVE, Ticket::FOLDER_SPAM, 'any'], true)) {
            throw new LocalizedException(
                __('The "folder" argument must be "inbox", "archive", "spam" or "any".')
            );
        }
        if ($folder !== 'any') {
            $collection->addFieldToFilter('folder', $folder);
        }

        $this->applyScalarFilters($collection, $arguments);

        $sortBy = (string) $this->optionalString($arguments, 'sort_by', 'created_at');
        $direction = strtoupper((string) $this->optionalString($arguments, 'sort_direction', 'DESC'));
        $collection->setOrder($sortBy, $direction === 'ASC' ? 'ASC' : 'DESC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $ticket) {
            /** @var Ticket $ticket */
            $items[] = $this->projector->toArray($ticket);
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'folder' => $folder,
            'items' => $items,
        ];
    }

    /**
     * @param object $collection
     * @param array<string, mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    private function applyScalarFilters(object $collection, array $arguments): void
    {
        $email = $this->optionalString($arguments, 'customer_email');
        if ($email !== null) {
            $collection->addFieldToFilter('customer_email', $email);
        }

        $channel = $this->optionalString($arguments, 'channel');
        if ($channel !== null) {
            $collection->addFieldToFilter('channel', $channel);
        }

        $columns = [
            'status_id' => 'status_id',
            'department_id' => 'department_id',
            'priority_id' => 'priority_id',
            'assigned_user_id' => 'user_id',
            'customer_id' => 'customer_id',
            'order_id' => 'order_id',
            'store_id' => 'store_id',
        ];
        foreach ($columns as $argument => $column) {
            $value = $this->optionalInt($arguments, $argument);
            if ($value !== null) {
                $collection->addFieldToFilter($column, $value);
            }
        }

        $unassigned = $this->optionalBool($arguments, 'unassigned');
        if ($unassigned === true) {
            if ($this->optionalInt($arguments, 'assigned_user_id') !== null) {
                throw new LocalizedException(__(
                    'Pass either "unassigned" or "assigned_user_id", not both — together they '
                    . 'match nothing.'
                ));
            }
            $collection->addFieldToFilter('user_id', ['null' => true]);
        } elseif ($unassigned === false) {
            $collection->addFieldToFilter('user_id', ['notnull' => true]);
        }

        $isLocked = $this->optionalBool($arguments, 'is_locked');
        if ($isLocked !== null) {
            $collection->addFieldToFilter('is_locked', $isLocked ? 1 : 0);
        }

        $createdFrom = $this->optionalString($arguments, 'created_from');
        if ($createdFrom !== null) {
            $collection->addFieldToFilter('created_at', ['gteq' => $createdFrom]);
        }
        $createdTo = $this->optionalString($arguments, 'created_to');
        if ($createdTo !== null) {
            $collection->addFieldToFilter('created_at', ['lteq' => $createdTo]);
        }
    }
}
