<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * What a valid OAuth access token stands for: an admin, through a client, with some scopes.
 */
class AccessGrant
{
    /**
     * @param int $adminUserId
     * @param string $username
     * @param string $clientName
     * @param string[] $scopes
     */
    public function __construct(
        private readonly int $adminUserId,
        private readonly string $username,
        private readonly string $clientName,
        private readonly array $scopes
    ) {
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
