<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Auth;

/**
 * The authenticated caller behind an MCP request, and what it is allowed to do.
 */
class Identity
{
    /**
     * @param int $userType One of \Magento\Authorization\Model\UserContextInterface::USER_TYPE_*
     * @param int $userId Integration id or admin user id, depending on $userType
     * @param string $label Human-readable name used in the audit log
     * @param string[] $allowedResources ACL resource ids granted to this caller
     * @param bool $writeScope False when the token itself is read-only (an OAuth grant
     *     without mcp:write), whatever the role allows. Integration tokens carry no scope.
     * @param string|null $connector The credential behind the call: the integration's name, the
     *     OAuth client's name, or null for an admin-user token. Recorded in the admin activity log.
     * @param string[]|null $domains Tool domains the credential is limited to: what the admin ticked
     *     when approving an OAuth connection, within the client's cap. Null for no such limit.
     */
    public function __construct(
        private readonly int $userType,
        private readonly int $userId,
        private readonly string $label,
        private readonly array $allowedResources,
        private readonly bool $writeScope = true,
        private readonly ?string $connector = null,
        private readonly ?array $domains = null
    ) {
    }

    /**
     * The tool domains the credential is limited to, or null when it is not.
     *
     * Narrows, never widens, like the write scope.
     *
     * @return string[]|null
     */
    public function getDomains(): ?array
    {
        return $this->domains;
    }

    /**
     * @return string|null
     */
    public function getConnector(): ?string
    {
        return $this->connector;
    }

    /**
     * Whether the credential this caller presented may be used for writes at all.
     *
     * Narrows, never widens: the role and the store's write switch still have
     * to allow the write too.
     *
     * @return bool
     */
    public function hasWriteScope(): bool
    {
        return $this->writeScope;
    }

    /**
     * @return int
     */
    public function getUserType(): int
    {
        return $this->userType;
    }

    /**
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Whether this caller holds the given ACL resource.
     *
     * An empty resource means "no extra grant needed beyond reaching the
     * endpoint". `Magento_Backend::all` is the super-user grant Magento gives a
     * role with every resource selected.
     *
     * @param string $resource
     * @return bool
     */
    public function isAllowed(string $resource): bool
    {
        if ($resource === '') {
            return true;
        }

        return in_array('Magento_Backend::all', $this->allowedResources, true)
            || in_array($resource, $this->allowedResources, true);
    }
}
