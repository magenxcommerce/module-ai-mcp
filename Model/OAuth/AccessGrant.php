<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * What a valid OAuth access token stands for: an admin, through a client, with some scopes and tool domains.
 */
class AccessGrant
{
    /**
     * @param int $adminUserId
     * @param string $username
     * @param string $clientName
     * @param string[] $scopes
     * @param string[]|null $domains Tool domains this connection may use; null for every domain.
     */
    public function __construct(
        private readonly int $adminUserId,
        private readonly string $username,
        private readonly string $clientName,
        private readonly array $scopes,
        private readonly ?array $domains = null
    ) {
    }

    /**
     * The tool domains this connection may use, or null for every domain.
     *
     * @return string[]|null
     */
    public function getDomains(): ?array
    {
        return $this->domains;
    }

    /**
     * @return int
     */
    public function getAdminUserId(): int
    {
        return $this->adminUserId;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Whether the admin granted write access to this connection.
     *
     * @return bool
     */
    public function allowsWrites(): bool
    {
        return in_array(Scope::WRITE, $this->scopes, true);
    }
}
