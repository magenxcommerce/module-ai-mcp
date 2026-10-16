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

        return $row === null ? null : $row + ['client_name' => $this->clients[$row['client_id']]['client_name']];
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

                return $row + [
                    'client_name' => $this->clients[$row['client_id']]['client_name'],
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
        $count = 0;
        foreach ($this->tokens as $id => $row) {
            if ($row[$column] === $value && $row['revoked'] === 0) {
                $this->tokens[$id]['revoked'] = 1;
                $count++;
            }
        }

        return $count;
    }
}
