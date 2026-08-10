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
     */
    public function __construct(
        private readonly int $userType,
        private readonly int $userId,
        private readonly string $label,
        private readonly array $allowedResources
    ) {
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
