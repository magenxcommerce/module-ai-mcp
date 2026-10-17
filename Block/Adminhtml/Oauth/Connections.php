<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Block\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\DomainList;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\Storage;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * The clients administrators created, with their policies, and the live OAuth grants, newest first.
 */
class Connections extends Template
{
    /** @var array<string, int[]>|null */
    private ?array $approvers = null;

    /**
     * @param Context $context
     * @param Storage $storage
     * @param OAuthConfig $oauthConfig
     * @param CoreDate $date
     * @param ToolCatalog $catalog
     * @param DomainList $domainList
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Storage $storage,
        private readonly OAuthConfig $oauthConfig,
        private readonly CoreDate $date,
        private readonly ToolCatalog $catalog,
        private readonly DomainList $domainList,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClients(): array
    {
        return $this->storage->getAdminClients();
    }

    /**
     * The admins who may approve a client; empty for any admin whose role allows it.
     *
     * @param string $clientId
     * @return int[]
     */
    public function getApprovers(string $clientId): array
    {
        $this->approvers ??= $this->storage->getApproversByClient();

        return $this->approvers[$clientId] ?? [];
    }

    /**
     * Active admins, the candidates for an approver list.
     *
     * @return array<int, string> Username by user id.
     */
    public function getAdminUsers(): array
    {
        return $this->storage->getActiveAdminUsers();
    }

    /**
     * Every tool domain, code to label.
     *
     * @return array<string, string>
     */
    public function getDomainOptions(): array
    {
        return $this->catalog->getDomains();
    }

    /**
     * A stored domain list, decoded; null for every domain.
     *
     * @param mixed $stored
     * @return string[]|null
     */
    public function decodeDomains(mixed $stored): ?array
    {
        return $this->domainList->decode($stored === null ? null : (string) $stored);
    }

    /**
     * A stored domain list as the labels an admin reads.
     *
     * @param mixed $stored
     * @return string
     */
    public function describeDomains(mixed $stored): string
    {
        $domains = $this->decodeDomains($stored);
        if ($domains === null) {
            return (string) __('All');
        }

        $labels = $this->catalog->getDomains();

        return implode(', ', array_map(static fn (string $code): string => $labels[$code] ?? $code, $domains));
    }

    /**
     * What a second approval by the same admin may do, value to label.
     *
     * @return array<string, string>
     */
    public function getReapprovalOptions(): array
    {
        return [
            Storage::REAPPROVAL_ADD => (string) __('Add a connection beside the first'),
            Storage::REAPPROVAL_REPLACE => (string) __('Replace the earlier connection'),
            Storage::REAPPROVAL_REJECT => (string) __('Refuse while one is live'),
        ];
    }

    /**
     * Whether anyone may register a client, which this page should warn about.
     *
     * @return bool
     */
    public function isDynamicRegistrationAllowed(): bool
    {
        return $this->oauthConfig->isDynamicRegistrationAllowed();
    }

    /**
     * Whether OAuth connections must carry the connector key.
     *
     * @return bool
     */
    public function isConnectorKeySet(): bool
    {
        return $this->oauthConfig->getConnectorKeyHash() !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGrants(): array
    {
        return $this->storage->getActiveGrants((int) $this->date->gmtTimestamp());
    }

    /**
     * A Unix timestamp in the admin's locale and time zone.
     *
     * @param mixed $timestamp
     * @return string
     */
    public function formatTimestamp(mixed $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return (string) __('Never');
        }

        return $this->formatDate(
            (new \DateTime('@' . (int) $timestamp)),
            \IntlDateFormatter::MEDIUM,
            true
        );
    }

    /**
     * @return string
     */
    public function getRevokeUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/revokeConnection');
    }

    /**
     * @return string
     */
    public function getCreateClientUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/createClient');
    }

    /**
     * @return string
     */
    public function getSaveClientUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/saveClient');
    }

    /**
     * @return string
     */
    public function getClientStatusUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/setClientStatus');
    }

    /**
     * @return string
     */
    public function getRotateSecretUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/rotateClientSecret');
    }

    /**
     * @return string
     */
    public function getDeleteClientUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/deleteClient');
    }

    /**
     * @return string
     */
    public function getConnectorKeyUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/connectorKey');
    }
}
