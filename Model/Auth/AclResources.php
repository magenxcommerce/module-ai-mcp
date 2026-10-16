<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Auth;

use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\UserContextInterface;

/**
 * The ACL resources a token's user holds.
 *
 * `AclRetriever::getAllowedResourcesByUser()` reads the rules stored against
 * the user's own role row. That is right for an integration, whose resources
 * are kept on its own row, and wrong for an admin: an admin's row is a
 * membership row (role_type U) whose parent is the group role (role_type G)
 * that actually carries the rules, so it resolves to nothing and every admin
 * — every role, Administrators included — is refused the endpoint. For an
 * admin the rules are read from that parent group instead.
 */
class AclResources
{
    /**
     * @param AclRetriever $aclRetriever
     * @param RoleCollectionFactory $roleCollectionFactory
     */
    public function __construct(
        private readonly AclRetriever $aclRetriever,
        private readonly RoleCollectionFactory $roleCollectionFactory
    ) {
    }

    /**
     * Resource ids granted to the user; empty when it has no role.
     *
     * @param int $userType
     * @param int $userId
     * @return string[]
     */
    public function forUser(int $userType, int $userId): array
    {
        try {
            if ($userType !== UserContextInterface::USER_TYPE_ADMIN) {
                return $this->aclRetriever->getAllowedResourcesByUser($userType, $userId);
            }

            $membership = $this->roleCollectionFactory->create()
                ->setUserFilter($userId, $userType)
                ->getFirstItem();
            $groupId = (int) $membership->getData('parent_id');

            return $groupId > 0 ? $this->aclRetriever->getAllowedResourcesByRole($groupId) : [];
        } catch (\Throwable) {
            // No role, or a role that resolves to nothing: grant nothing.
            return [];
        }
    }
}
