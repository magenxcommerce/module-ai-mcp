<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\User;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * Find admin users, and the ids the assignment tools take.
 *
 * `assign_helpdesk_ticket` refuses an `admin_user_id` that does not exist, but
 * nothing on this surface reported what those ids are — so assigning work meant
 * knowing the number already, and an agent had no way to find it. This is the
 * lookup that closes that gap; help desk tickets assign to core admin users, so
 * one lookup serves both.
 *
 * Magento ships no service contract for admin users: its own grid reads the
 * collection, and so does this. That is a concrete-class dependency rather than
 * an `@api` one, the same compromise the help desk tools document.
 *
 * The projection is a whitelist rather than the row. `admin_user` also holds the
 * password hash, the password-reset token and the lockout counters, and a
 * lookup that handed those back would turn "who can I assign this to" into
 * credential disclosure.
 */
class SearchAdminUsers extends AbstractTool
{
    /** Columns a free-text query is matched against, as an OR. */
    private const QUERY_COLUMNS = ['username', 'firstname', 'lastname', 'email'];

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
        return 'search_admin_users';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find admin users by username, name or email, and read the admin_user_id that '
            . 'assign_helpdesk_ticket takes. Returns who each account is and whether it is still '
            . 'active — never a password, reset token or lockout state. Ordered by username.';
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
                        'description' => 'Free text matched as a substring against username, '
                            . 'firstname, lastname and email.',
                    ],
                    'email' => ['type' => 'string', 'description' => 'Exact match.'],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Restrict to enabled or disabled accounts. A disabled '
                            . 'account can still hold tickets but cannot sign in to work them.',
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
        return 'Magento_User::acl_users';
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
            // An array of fields against an array of conditions is Magento's
            // OR: one match in any of the four is enough.
            $condition = ['like' => '%' . $query . '%'];
            $collection->addFieldToFilter(
                self::QUERY_COLUMNS,
                array_fill(0, count(self::QUERY_COLUMNS), $condition)
            );
        }

        $email = $this->optionalString($arguments, 'email');
        if ($email !== null) {
            $collection->addFieldToFilter('email', $email);
        }

        $isActive = $this->optionalBool($arguments, 'is_active');
        if ($isActive !== null) {
            $collection->addFieldToFilter('is_active', $isActive ? 1 : 0);
        }

        $collection->setOrder('username', 'ASC');
        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage);

        $items = [];
        foreach ($collection as $user) {
            $items[] = [
                // Named as assign_helpdesk_ticket's argument rather than as the
                // column, so the id reads across from one tool to the other.
                'admin_user_id' => (int) $user->getId(),
                'username' => $user->getData('username'),
                'name' => $this->name($user),
                'email' => $user->getData('email'),
                'is_active' => (bool) $user->getData('is_active'),
                'last_login_at' => $user->getData('logdate'),
            ];
        }

        return [
            'total_count' => (int) $collection->getSize(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }

    /**
     * The account holder's name, or null where the record carries neither part.
     *
     * @param object $user
     * @return string|null
     */
    private function name(object $user): ?string
    {
        $name = trim((string) $user->getData('firstname') . ' ' . (string) $user->getData('lastname'));

        return $name === '' ? null : $name;
    }
}
