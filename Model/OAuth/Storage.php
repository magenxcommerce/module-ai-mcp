<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * The OAuth tables, read and written directly.
 *
 * Plain rows rather than models, repositories and collections: nothing else
 * in Magento needs to load these, nothing should be able to save one through
 * a generic path, and every state change here is a single conditional UPDATE
 * whose affected-row count is the answer — which is what makes a code or a
 * refresh token single-use even under two concurrent requests.
 *
 * Times are Unix timestamps throughout, so comparisons never depend on the
 * database's or PHP's time zone.
 */
class Storage
{
    public const TABLE_CLIENT = 'magenx_ai_mcp_oauth_client';
    public const TABLE_AUTHORIZATION = 'magenx_ai_mcp_oauth_authorization';
    public const TABLE_TOKEN = 'magenx_ai_mcp_oauth_token';
    public const TABLE_CLIENT_APPROVER = 'magenx_ai_mcp_oauth_client_approver';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_USED = 'used';
    public const STATUS_DENIED = 'denied';

    /** Registered by anyone through RFC 7591. */
    public const ORIGIN_DYNAMIC = 'dynamic';

    /** Created by an administrator, who handed its id and secret to the client. */
    public const ORIGIN_ADMIN = 'admin';

    /** A second approval by the same admin adds a connection beside the first. */
    public const REAPPROVAL_ADD = 'add';

    /** A second approval by the same admin replaces their earlier connections once its code is redeemed. */
    public const REAPPROVAL_REPLACE = 'replace';

    /** A second approval by the same admin is refused while an earlier connection is live. */
    public const REAPPROVAL_REJECT = 'reject';

    /** @var string[] */
    public const REAPPROVAL_MODES = [self::REAPPROVAL_ADD, self::REAPPROVAL_REPLACE, self::REAPPROVAL_REJECT];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    public function insertClient(array $row): void
    {
        $this->connection()->insert($this->table(self::TABLE_CLIENT), $row);
    }

    /**
     * @param string $clientId
     * @return array<string, mixed>|null
     */
    public function getClient(string $clientId): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table(self::TABLE_CLIENT))->where('client_id = ?', $clientId)
        );

        return $row ?: null;
    }

    /**
     * Clients an administrator created, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminClients(): array
    {
        $connection = $this->connection();

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->table(self::TABLE_CLIENT),
                    ['client_id', 'client_name', 'created_at', 'disabled', 'allowed_domains', 'reapproval']
                )
                ->where('origin = ?', self::ORIGIN_ADMIN)
                ->order('created_at DESC')
        );
    }

    /**
     * Delete a client an administrator created. Its requests and tokens go with it.
     *
     * @param string $clientId
     * @return bool Whether a client was deleted.
     */
    public function deleteAdminClient(string $clientId): bool
    {
        return $this->connection()->delete(
            $this->table(self::TABLE_CLIENT),
            ['client_id = ?' => $clientId, 'origin = ?' => self::ORIGIN_ADMIN]
        ) === 1;
    }

    /**
     * Change the policy columns of a client an administrator created.
     *
     * @param string $clientId
     * @param array<string, mixed> $changes Any of disabled, allowed_domains, reapproval, secret_hash.
     * @return bool Whether such a client exists.
     */
    public function updateAdminClient(string $clientId, array $changes): bool
    {
        $connection = $this->connection();
        $where = ['client_id = ?' => $clientId, 'origin = ?' => self::ORIGIN_ADMIN];
        $connection->update($this->table(self::TABLE_CLIENT), $changes, $where);

        // The affected-row count is 0 for an update that changes nothing, so
        // existence is asked separately.
        return (bool) $connection->fetchOne(
            $connection->select()->from($this->table(self::TABLE_CLIENT), ['client_id'])
                ->where('client_id = ?', $clientId)
                ->where('origin = ?', self::ORIGIN_ADMIN)
        );
    }

    /**
     * The admins allowed to approve a client. Empty means any admin whose role allows approving.
     *
     * @param string $clientId
     * @return int[]
     */
    public function getClientApprovers(string $clientId): array
    {
        $connection = $this->connection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->table(self::TABLE_CLIENT_APPROVER), ['admin_user_id'])
                ->where('client_id = ?', $clientId)
        ));
    }

    /**
     * Every client's approvers, keyed by client id, for listing many at once.
     *
     * @return array<string, int[]>
     */
    public function getApproversByClient(): array
    {
        $connection = $this->connection();
        $result = [];
        foreach ($connection->fetchAll(
            $connection->select()->from($this->table(self::TABLE_CLIENT_APPROVER), ['client_id', 'admin_user_id'])
        ) as $row) {
            $result[(string) $row['client_id']][] = (int) $row['admin_user_id'];
        }

        return $result;
    }

    /**
     * Replace a client's approver list.
     *
     * @param string $clientId
     * @param int[] $adminUserIds
     * @return void
     */
    public function setClientApprovers(string $clientId, array $adminUserIds): void
    {
        $connection = $this->connection();
        $table = $this->table(self::TABLE_CLIENT_APPROVER);
        $connection->beginTransaction();
        try {
            $connection->delete($table, ['client_id = ?' => $clientId]);
            if ($adminUserIds !== []) {
                $connection->insertMultiple($table, array_map(
                    static fn (int $id): array => ['client_id' => $clientId, 'admin_user_id' => $id],
                    $adminUserIds
                ));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * Active admin users, for choosing approvers.
     *
     * @return array<int, string> Username by user id.
     */
    public function getActiveAdminUsers(): array
    {
        $connection = $this->connection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->table('admin_user'), ['user_id', 'username'])
                ->where('is_active = ?', 1)
                ->order('username ASC')
        );

        $result = [];
        foreach ($rows as $id => $username) {
            $result[(int) $id] = (string) $username;
        }

        return $result;
    }

    /**
     * Mark a client as having completed a flow, which exempts it from the purge of abandoned registrations.
     *
     * A client caches its registration and reuses it to reconnect long after
     * its tokens expired; deleting it would turn that reconnect into an
     * "unknown client" error the user can only fix by re-adding the connector.
     *
     * @param string $clientId
     * @return void
     */
    public function markClientGranted(string $clientId): void
    {
        $this->connection()->update(
            $this->table(self::TABLE_CLIENT),
            ['granted' => 1],
            ['client_id = ?' => $clientId, 'granted = ?' => 0]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    public function insertAuthorization(array $row): void
    {
        $this->connection()->insert($this->table(self::TABLE_AUTHORIZATION), $row);
    }

    /**
     * A pending or decided request, with its client's name.
     *
     * @param string $requestId
     * @return array<string, mixed>|null
     */
    public function getAuthorization(string $requestId): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['a' => $this->table(self::TABLE_AUTHORIZATION)])
                ->joinInner(
                    ['c' => $this->table(self::TABLE_CLIENT)],
                    'c.client_id = a.client_id',
                    [
                        'client_name',
                        'client_disabled' => 'disabled',
                        'client_domains' => 'allowed_domains',
                        'client_reapproval' => 'reapproval',
                    ]
                )
                ->where('a.request_id = ?', $requestId)
        );

        return $row ?: null;
    }

    /**
     * @param string $codeHash
     * @return array<string, mixed>|null
     */
    public function getAuthorizationByCode(string $codeHash): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(self::TABLE_AUTHORIZATION))
                ->where('code_hash = ?', $codeHash)
        );

        return $row ?: null;
    }

    /**
     * Move a request from one status to another, only if it is still in the first.
     *
     * @param string $requestId
     * @param string $fromStatus
     * @param array<string, mixed> $changes Must include the new `status`.
     * @return bool Whether this call made the change.
     */
    public function transitionAuthorization(string $requestId, string $fromStatus, array $changes): bool
    {
        return $this->connection()->update(
            $this->table(self::TABLE_AUTHORIZATION),
            $changes,
            ['request_id = ?' => $requestId, 'status = ?' => $fromStatus]
        ) === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    public function insertToken(array $row): void
    {
        $this->connection()->insert($this->table(self::TABLE_TOKEN), $row);
    }

    /**
     * A token row found by the hash of its access token, with the admin and client it belongs to.
     *
     * The admin join is inner, so a token whose admin was deleted is simply not found.
     *
     * @param string $accessHash
     * @return array<string, mixed>|null
     */
    public function getTokenByAccessHash(string $accessHash): ?array
    {
        return $this->getTokenWhere('t.access_hash = ?', $accessHash);
    }

    /**
     * @param string $refreshHash
     * @return array<string, mixed>|null
     */
    public function getTokenByRefreshHash(string $refreshHash): ?array
    {
        return $this->getTokenWhere('t.refresh_hash = ?', $refreshHash);
    }

    /**
     * Mark a refresh token spent, only if nobody else spent it first.
     *
     * @param int $entityId
     * @return bool
     */
    public function markRotated(int $entityId): bool
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['rotated' => 1],
            ['entity_id = ?' => $entityId, 'rotated = ?' => 0, 'revoked = ?' => 0]
        ) === 1;
    }

    /**
     * Revoke every token issued under one grant.
     *
     * @param string $familyId
     * @return int Rows revoked.
     */
    public function revokeFamily(string $familyId): int
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['revoked' => 1],
            ['family_id = ?' => $familyId, 'revoked = ?' => 0]
        );
    }

    /**
     * Revoke every token that came from one authorization code.
     *
     * @param string $requestId
     * @return int
     */
    public function revokeByAuthorization(string $requestId): int
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['revoked' => 1],
            ['authorization_id = ?' => $requestId, 'revoked = ?' => 0]
        );
    }

    /**
     * Revoke every grant an admin holds.
     *
     * @param int $adminUserId
     * @return int
     */
    public function revokeAdmin(int $adminUserId): int
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['revoked' => 1],
            ['admin_user_id = ?' => $adminUserId, 'revoked = ?' => 0]
        );
    }

    /**
     * Whether an admin holds a live connection through a client.
     *
     * @param string $clientId
     * @param int $adminUserId
     * @param int $now
     * @return bool
     */
    public function hasLiveGrant(string $clientId, int $adminUserId, int $now): bool
    {
        $connection = $this->connection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->table(self::TABLE_TOKEN), ['entity_id'])
                ->where('client_id = ?', $clientId)
                ->where('admin_user_id = ?', $adminUserId)
                ->where('revoked = ?', 0)
                ->where('refresh_expires_at > ?', $now)
                ->limit(1)
        );
    }

    /**
     * Revoke an admin's connections through a client, except one grant.
     *
     * @param string $clientId
     * @param int $adminUserId
     * @param string $keepFamilyId
     * @return int Rows revoked.
     */
    public function revokeOtherGrants(string $clientId, int $adminUserId, string $keepFamilyId): int
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['revoked' => 1],
            [
                'client_id = ?' => $clientId,
                'admin_user_id = ?' => $adminUserId,
                'family_id <> ?' => $keepFamilyId,
                'revoked = ?' => 0,
            ]
        );
    }

    /**
     * Revoke every connection through a client held by an admin not on a list.
     *
     * @param string $clientId
     * @param int[] $keepAdminUserIds Must not be empty.
     * @return int Rows revoked.
     */
    public function revokeClientGrantsExcept(string $clientId, array $keepAdminUserIds): int
    {
        return $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['revoked' => 1],
            [
                'client_id = ?' => $clientId,
                'admin_user_id NOT IN (?)' => $keepAdminUserIds,
                'revoked = ?' => 0,
            ]
        );
    }

    /**
     * Record use, at most once a minute per token so the MCP endpoint does not write on every call.
     *
     * @param int $entityId
     * @param int $now
     * @return void
     */
    public function touch(int $entityId, int $now): void
    {
        $this->connection()->update(
            $this->table(self::TABLE_TOKEN),
            ['last_used_at' => $now],
            ['entity_id = ?' => $entityId, '(last_used_at IS NULL OR last_used_at < ?)' => $now - 60]
        );
    }

    /**
     * Live grants: one row per family, its newest token.
     *
     * @param int $now
     * @return array<int, array<string, mixed>>
     */
    public function getActiveGrants(int $now): array
    {
        $connection = $this->connection();
        $latest = $connection->select()
            ->from($this->table(self::TABLE_TOKEN), ['entity_id' => new Expression('MAX(entity_id)')])
            ->where('revoked = ?', 0)
            ->where('refresh_expires_at > ?', $now)
            ->group('family_id');

        $select = $connection->select()
            ->from(['l' => $latest], [])
            ->joinInner(['t' => $this->table(self::TABLE_TOKEN)], 't.entity_id = l.entity_id')
            ->joinInner(
                ['c' => $this->table(self::TABLE_CLIENT)],
                'c.client_id = t.client_id',
                ['client_name', 'client_disabled' => 'disabled']
            )
            ->joinInner(['u' => $this->table('admin_user')], 'u.user_id = t.admin_user_id', ['username'])
            ->order('t.entity_id DESC');

        return $connection->fetchAll($select);
    }

    /**
     * Delete what can no longer be used.
     *
     * Rotated refresh tokens are kept until their grant expires, because a
     * replayed one is how theft is detected; revoked ones are dropped once
     * their access token has run out too.
     *
     * @param int $now
     * @return void
     */
    public function purge(int $now): void
    {
        $connection = $this->connection();
        $connection->delete(
            $this->table(self::TABLE_AUTHORIZATION),
            ['expires_at < ?' => $now - 3600]
        );
        $connection->delete(
            $this->table(self::TABLE_TOKEN),
            ['refresh_expires_at < ?' => $now]
        );
        $connection->delete(
            $this->table(self::TABLE_TOKEN),
            ['revoked = ?' => 1, 'access_expires_at < ?' => $now]
        );

        // Registered clients that never completed a flow. Registration can be
        // open, so this is what keeps abandoned attempts from accumulating.
        // A client an administrator created waits, unused, until it is deleted.
        $connection->delete(
            $this->table(self::TABLE_CLIENT),
            [
                'origin = ?' => self::ORIGIN_DYNAMIC,
                'granted = ?' => 0,
                'created_at < ?' => $now - 3600,
                sprintf(
                    'client_id NOT IN (SELECT client_id FROM %s)',
                    $connection->quoteIdentifier($this->table(self::TABLE_TOKEN))
                ),
                sprintf(
                    'client_id NOT IN (SELECT client_id FROM %s)',
                    $connection->quoteIdentifier($this->table(self::TABLE_AUTHORIZATION))
                ),
            ]
        );
    }

    /**
     * @param string $condition
     * @param string $value
     * @return array<string, mixed>|null
     */
    private function getTokenWhere(string $condition, string $value): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['t' => $this->table(self::TABLE_TOKEN)])
                ->joinInner(
                    ['c' => $this->table(self::TABLE_CLIENT)],
                    'c.client_id = t.client_id',
                    ['client_name', 'client_disabled' => 'disabled', 'client_domains' => 'allowed_domains']
                )
                ->joinInner(
                    ['u' => $this->table('admin_user')],
                    'u.user_id = t.admin_user_id',
                    ['username', 'admin_is_active' => 'is_active', 'admin_lock_expires' => 'lock_expires']
                )
                ->where($condition, $value)
        );

        return $row ?: null;
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
