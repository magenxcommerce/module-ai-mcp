<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Framework\App\ResourceConnection;

/**
 * The OAuth tables in memory, with the same conditional-update semantics.
 *
 * Lets the services be driven through whole flows — authorize, approve,
 * exchange, refresh, replay — without a database, and lets a test look at
 * exactly what was stored.
 */
class FakeStorage extends Storage
{
    /** @var array<string, array<string, mixed>> */
    public array $clients = [];

    /** @var array<string, array<string, mixed>> */
    public array $authorizations = [];

    /** @var array<int, array<string, mixed>> */
    public array $tokens = [];

    /** @var array<int, array{username: string, is_active: int, lock_expires: string|null}> */
    public array $admins = [7 => ['username' => 'alice', 'is_active' => 1, 'lock_expires' => null]];

    /** @var array<string, int[]> */
    public array $approvers = [];

    private int $nextTokenId = 1;

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new ResourceConnection());
    }

    /**
     * @inheritDoc
     */
    public function insertClient(array $row): void
    {
        $this->clients[$row['client_id']] = $row;
    }

    /**
     * @inheritDoc
     */
    public function getClient(string $clientId): ?array
    {
        return $this->clients[$clientId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getAdminClients(): array
    {
        $clients = array_values(array_filter(
            $this->clients,
            static fn (array $client): bool => ($client['origin'] ?? null) === Storage::ORIGIN_ADMIN
        ));
        usort($clients, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $clients;
    }

    /**
     * Deletes the client's requests and tokens too, as the foreign keys do.
     *
     * @inheritDoc
     */
    public function deleteAdminClient(string $clientId): bool
    {
        if (($this->clients[$clientId]['origin'] ?? null) !== Storage::ORIGIN_ADMIN) {
            return false;
        }

        unset($this->clients[$clientId]);
        $belongs = static fn (array $row): bool => $row['client_id'] !== $clientId;
        $this->authorizations = array_filter($this->authorizations, $belongs);
        $this->tokens = array_filter($this->tokens, $belongs);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function updateAdminClient(string $clientId, array $changes): bool
    {
        if (($this->clients[$clientId]['origin'] ?? null) !== Storage::ORIGIN_ADMIN) {
            return false;
        }
        $this->clients[$clientId] = $changes + $this->clients[$clientId];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getClientApprovers(string $clientId): array
    {
        return $this->approvers[$clientId] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getApproversByClient(): array
    {
        return $this->approvers;
    }

    /**
     * @inheritDoc
     */
    public function setClientApprovers(string $clientId, array $adminUserIds): void
    {
        $this->approvers[$clientId] = $adminUserIds;
        if ($adminUserIds === []) {
            unset($this->approvers[$clientId]);
        }
    }

    /**
     * @inheritDoc
     */
    public function getActiveAdminUsers(): array
    {
        $result = [];
        foreach ($this->admins as $id => $admin) {
            if ($admin['is_active'] === 1) {
                $result[$id] = $admin['username'];
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function hasLiveGrant(string $clientId, int $adminUserId, int $now): bool
    {
        foreach ($this->tokens as $row) {
            if ($row['client_id'] === $clientId && $row['admin_user_id'] === $adminUserId
                && $row['revoked'] === 0 && $row['refresh_expires_at'] > $now
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function revokeOtherGrants(string $clientId, int $adminUserId, string $keepFamilyId): int
    {
        return $this->revokeMatching(static fn (array $row): bool => $row['client_id'] === $clientId
            && $row['admin_user_id'] === $adminUserId
            && $row['family_id'] !== $keepFamilyId);
    }

    /**
     * @inheritDoc
     */
    public function revokeClientGrantsExcept(string $clientId, array $keepAdminUserIds): int
    {
        return $this->revokeMatching(static fn (array $row): bool => $row['client_id'] === $clientId
            && !in_array($row['admin_user_id'], $keepAdminUserIds, true));
    }

    /**
     * @inheritDoc
     */
    public function markClientGranted(string $clientId): void
    {
        $this->clients[$clientId]['granted'] = 1;
    }

    /**
     * @inheritDoc
     */
    public function insertAuthorization(array $row): void
    {
        $this->authorizations[$row['request_id']] = $row + [
            'admin_user_id' => null,
            'granted_scope' => null,
            'code_hash' => null,
            'code_expires_at' => null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAuthorization(string $requestId): ?array
    {
        $row = $this->authorizations[$requestId] ?? null;

        if ($row === null) {
            return null;
        }
        $client = $this->clients[$row['client_id']];

        return $row + [
            'client_name' => $client['client_name'],
            'client_disabled' => $client['disabled'] ?? 0,
            'client_domains' => $client['allowed_domains'] ?? null,
            'client_reapproval' => $client['reapproval'] ?? Storage::REAPPROVAL_ADD,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAuthorizationByCode(string $codeHash): ?array
    {
        foreach ($this->authorizations as $row) {
            if ($row['code_hash'] === $codeHash) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function transitionAuthorization(string $requestId, string $fromStatus, array $changes): bool
    {
        if (($this->authorizations[$requestId]['status'] ?? null) !== $fromStatus) {
            return false;
        }
        $this->authorizations[$requestId] = $changes + $this->authorizations[$requestId];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function insertToken(array $row): void
    {
        $row['entity_id'] = $this->nextTokenId++;
        $row['last_used_at'] = null;
        $this->tokens[$row['entity_id']] = $row;
    }

    /**
     * @inheritDoc
     */
    public function getTokenByAccessHash(string $accessHash): ?array
    {
        return $this->findToken('access_hash', $accessHash);
    }

    /**
     * @inheritDoc
     */
    public function getTokenByRefreshHash(string $refreshHash): ?array
    {
        return $this->findToken('refresh_hash', $refreshHash);
    }

    /**
     * @inheritDoc
     */
    public function markRotated(int $entityId): bool
    {
        $row = $this->tokens[$entityId] ?? null;
        if ($row === null || $row['rotated'] !== 0 || $row['revoked'] !== 0) {
            return false;
        }
        $this->tokens[$entityId]['rotated'] = 1;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function revokeFamily(string $familyId): int
    {
        return $this->revokeWhere('family_id', $familyId);
    }

    /**
     * @inheritDoc
     */
    public function revokeByAuthorization(string $requestId): int
    {
        return $this->revokeWhere('authorization_id', $requestId);
    }

    /**
     * @inheritDoc
     */
    public function revokeAdmin(int $adminUserId): int
    {
        return $this->revokeWhere('admin_user_id', $adminUserId);
    }

    /**
     * @inheritDoc
     */
    public function touch(int $entityId, int $now): void
    {
        $this->tokens[$entityId]['last_used_at'] = $now;
    }

    /**
     * @param string $column
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function findToken(string $column, mixed $value): ?array
    {
        foreach ($this->tokens as $row) {
            if ($row[$column] === $value && isset($this->admins[$row['admin_user_id']])) {
                $admin = $this->admins[$row['admin_user_id']];

                $client = $this->clients[$row['client_id']];

                return $row + [
                    'client_name' => $client['client_name'],
                    'client_disabled' => $client['disabled'] ?? 0,
                    'client_domains' => $client['allowed_domains'] ?? null,
                    'username' => $admin['username'],
                    'admin_is_active' => $admin['is_active'],
                    'admin_lock_expires' => $admin['lock_expires'],
                ];
            }
        }

        return null;
    }

    /**
     * @param string $column
     * @param mixed $value
     * @return int
     */
    private function revokeWhere(string $column, mixed $value): int
    {
        return $this->revokeMatching(static fn (array $row): bool => $row[$column] === $value);
    }

    /**
     * @param callable(array<string, mixed>): bool $matches
     * @return int
     */
    private function revokeMatching(callable $matches): int
    {
        $count = 0;
        foreach ($this->tokens as $id => $row) {
            if ($matches($row) && $row['revoked'] === 0) {
                $this->tokens[$id]['revoked'] = 1;
                $count++;
            }
        }

        return $count;
    }
}
