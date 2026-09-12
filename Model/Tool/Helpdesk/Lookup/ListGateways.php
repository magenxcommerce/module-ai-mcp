<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Helpdesk\Lookup;

use Magenx\Helpdesk\Model\ResourceModel\Gateway\CollectionFactory;

/**
 * List the inbound mailboxes tickets arrive through.
 *
 * The one lookup here that holds a secret. The gateway row stores the mailbox
 * password alongside its host and login, and this tool never returns it — only
 * whether one is set. That is a deliberate floor, not an oversight: a read tool
 * that hands back working IMAP credentials turns "list the mailboxes" into
 * credential exfiltration, and an agent has no use for the password that
 * reading `last_error` does not serve better.
 *
 * Writing a gateway is not exposed at all, for the same reason in reverse:
 * a tool that sets host and login could point the store's mail intake at a
 * mailbox someone else controls.
 */
class ListGateways extends AbstractHelpdeskList
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
        return 'list_helpdesk_gateways';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the email gateways tickets arrive through, with their host, port, folder and '
            . 'the time and error of their last fetch — which is what answers "why have no '
            . 'tickets come in". Passwords are never returned, only whether one is set, and '
            . 'gateways cannot be created or edited through this server.';
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Helpdesk::gateway';
    }

    /**
     * @inheritDoc
     */
    protected function entityName(): string
    {
        return 'email gateways';
    }

    /**
     * @inheritDoc
     */
    protected function orderColumn(): string
    {
        return 'gateway_id';
    }

    /**
     * @inheritDoc
     */
    protected function createCollection(): object
    {
        return $this->collectionFactory->create();
    }

    /**
     * @inheritDoc
     */
    protected function projectRow(object $row): array
    {
        return [
            'gateway_id' => (int) $row->getId(),
            'title' => $row->getData('title'),
            'email' => $row->getData('email'),
            'login' => $row->getData('login'),
            // Never the password itself. Whether one is stored is all that is
            // useful for diagnosing a gateway that cannot connect.
            'password_set' => ((string) $row->getData('password')) !== '',
            'host' => $row->getData('host'),
            'port' => $row->getData('port') === null ? null : (int) $row->getData('port'),
            'encryption' => $row->getData('encryption'),
            'folder' => $row->getData('folder'),
            'is_active' => (bool) $row->getData('is_active'),
            'fetch_limit' => $row->getData('fetch_limit') === null
                ? null
                : (int) $row->getData('fetch_limit'),
            'remove_after_fetch' => (bool) $row->getData('remove_after_fetch'),
            'store_id' => $row->getData('store_id') === null ? null : (int) $row->getData('store_id'),
            'department_id' => $row->getData('department_id') === null
                ? null
                : (int) $row->getData('department_id'),
            'last_fetch_at' => $row->getData('last_fetch_at'),
            // The most useful field on this row when mail has stopped arriving.
            'last_error' => $row->getData('last_error'),
        ];
    }
}
