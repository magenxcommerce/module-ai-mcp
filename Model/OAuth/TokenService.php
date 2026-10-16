<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Psr\Log\LoggerInterface;

/**
 * Issues, rotates, revokes and resolves tokens.
 *
 * A grant is a family of token rows sharing one `family_id`. Each refresh
 * spends the refresh token it was given and issues a new pair in the same
 * family; the family's absolute expiry never moves, so a grant always ends
 * and the admin has to sign in — and pass two-factor — again. A spent refresh
 * token presented a second time means two parties hold it, so the whole
 * family is revoked (OAuth 2.1 §4.3.1). A code redeemed twice does the same
 * to everything issued from it.
 */
class TokenService
{
    /**
     * @param Storage $storage
     * @param OAuthConfig $config
     * @param Endpoints $endpoints
     * @param Pkce $pkce
     * @param Scope $scope
     * @param Secret $secret
     * @param CoreDate $date
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly OAuthConfig $config,
        private readonly Endpoints $endpoints,
        private readonly Pkce $pkce,
        private readonly Scope $scope,
        private readonly Secret $secret,
        private readonly CoreDate $date,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * The authorization_code grant.
     *
     * @param array<string, mixed> $client The authenticated client.
     * @param array<string, mixed> $params The token request body.
     * @return array<string, mixed> The token response.
     * @throws OAuthException
     */
    public function exchangeCode(array $client, array $params): array
    {
        $code = $this->stringParam($params, 'code');
        if (!str_starts_with($code, Secret::PREFIX_CODE)) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid.');
        }

        $authorization = $this->storage->getAuthorizationByCode($this->secret->hash($code));
        if ($authorization === null || $authorization['client_id'] !== $client['client_id']) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid.');
        }

        $requestId = (string) $authorization['request_id'];
        if ($authorization['status'] === Storage::STATUS_USED) {
            $revoked = $this->storage->revokeByAuthorization($requestId);
            $this->auditLogger->warning('[magenx-mcp] oauth code replayed; tokens revoked', [
                'client_id' => $client['client_id'],
                'admin_user_id' => $authorization['admin_user_id'],
                'revoked' => $revoked,
            ]);

            throw new OAuthException('invalid_grant', 'The authorization code was already used.');
        }

        $now = (int) $this->date->gmtTimestamp();
        if ($authorization['status'] !== Storage::STATUS_APPROVED || (int) $authorization['code_expires_at'] < $now) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }

        $redirectUri = $this->stringParam($params, 'redirect_uri');
        if ($redirectUri !== '' && $redirectUri !== $authorization['redirect_uri']) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        if (!$this->pkce->verify($this->stringParam($params, 'code_verifier'), (string) $authorization['code_challenge'])) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }

        $this->assertResource($params);

        // The conditional update is what makes the code single-use: of two
        // concurrent redemptions, exactly one sees its row change.
        if (!$this->storage->transitionAuthorization(
            $requestId,
            Storage::STATUS_APPROVED,
            ['status' => Storage::STATUS_USED]
        )) {
            throw new OAuthException('invalid_grant', 'The authorization code was already used.');
        }

        $scopes = $this->scope->normalize(explode(' ', (string) $authorization['granted_scope']));

        return $this->issue(
            $client,
            (int) $authorization['admin_user_id'],
            $scopes,
            $this->secret->handle(),
            $now + $this->config->getRefreshTokenLifetime(),
            $requestId
        );
    }

    /**
     * The refresh_token grant, with rotation.
     *
     * @param array<string, mixed> $client
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OAuthException
     */
    public function refresh(array $client, array $params): array
    {
        $refreshToken = $this->stringParam($params, 'refresh_token');
        $row = str_starts_with($refreshToken, Secret::PREFIX_REFRESH)
            ? $this->storage->getTokenByRefreshHash($this->secret->hash($refreshToken))
            : null;
        if ($row === null || $row['client_id'] !== $client['client_id'] || (int) $row['revoked'] === 1) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid or has been revoked.');
        }

        if ((int) $row['rotated'] === 1) {
            $this->revokeReplayedFamily($row);

            throw new OAuthException('invalid_grant', 'The refresh token was already used.');
        }

        $now = (int) $this->date->gmtTimestamp();
        if ((int) $row['refresh_expires_at'] < $now) {
            throw new OAuthException('invalid_grant', 'This connection has expired. Connect again to sign in.');
        }
        if (!$this->isAdminUsable($row, $now)) {
            throw new OAuthException('invalid_grant', 'The administrator account behind this connection is inactive or locked.');
        }

        $this->assertResource($params);

        $held = $this->scope->normalize(explode(' ', (string) $row['scope']));
        $asked = $this->stringParam($params, 'scope');
        $scopes = $held;
        if ($asked !== '') {
            $scopes = $this->scope->parse($asked);
            if (array_diff($scopes, $held) !== []) {
                throw new OAuthException('invalid_scope', 'A refresh cannot widen the granted scope.');
            }
        }

        if (!$this->storage->markRotated((int) $row['entity_id'])) {
            // Lost a race with another redemption of the same token. Refuse
            // without revoking: the other request may well be the client's own.
            throw new OAuthException('invalid_grant', 'The refresh token was already used.');
        }

        return $this->issue(
            $client,
            (int) $row['admin_user_id'],
            $scopes,
            (string) $row['family_id'],
            (int) $row['refresh_expires_at'],
            $row['authorization_id'] === null ? null : (string) $row['authorization_id']
        );
    }

    /**
     * RFC 7009 revocation: the whole grant the token belongs to.
     *
     * Unknown tokens are not an error — the RFC answers 200 either way, so
     * the endpoint cannot be used to test whether a token exists.
     *
     * @param array<string, mixed> $client
     * @param string $token
     * @return void
     */
    public function revoke(array $client, string $token): void
    {
        $hash = $this->secret->hash($token);
        $row = match (true) {
            str_starts_with($token, Secret::PREFIX_ACCESS) => $this->storage->getTokenByAccessHash($hash),
            str_starts_with($token, Secret::PREFIX_REFRESH) => $this->storage->getTokenByRefreshHash($hash),
            default => null,
        };
        if ($row === null || $row['client_id'] !== $client['client_id']) {
            return;
        }

        $this->storage->revokeFamily((string) $row['family_id']);
        $this->auditLogger->info('[magenx-mcp] oauth grant revoked by client', [
            'client_id' => $client['client_id'],
            'admin_user_id' => $row['admin_user_id'],
        ]);
    }

    /**
     * Resolve an access token presented to the MCP endpoint.
     *
     * @param string $token
     * @return AccessGrant|null Null for anything not currently valid.
     */
    public function resolve(string $token): ?AccessGrant
    {
        if (!str_starts_with($token, Secret::PREFIX_ACCESS)) {
            return null;
        }

        $row = $this->storage->getTokenByAccessHash($this->secret->hash($token));
        $now = (int) $this->date->gmtTimestamp();
        if ($row === null
            || (int) $row['revoked'] === 1
            || (int) $row['access_expires_at'] < $now
            || !$this->isAdminUsable($row, $now)
            || ($row['resource'] !== null && $row['resource'] !== $this->endpoints->getResource())
        ) {
            return null;
        }

        $this->storage->touch((int) $row['entity_id'], $now);

        return new AccessGrant(
            (int) $row['admin_user_id'],
            (string) $row['username'],
            (string) $row['client_name'],
            $this->scope->normalize(explode(' ', (string) $row['scope']))
        );
    }

    /**
     * Mint an access/refresh pair in a family.
     *
     * @param array<string, mixed> $client
     * @param int $adminUserId
     * @param string[] $scopes
     * @param string $familyId
     * @param int $familyExpiresAt
     * @param string|null $authorizationId
     * @return array<string, mixed>
     */
    private function issue(
        array $client,
        int $adminUserId,
        array $scopes,
        string $familyId,
        int $familyExpiresAt,
        ?string $authorizationId
    ): array {
        $now = (int) $this->date->gmtTimestamp();
        $lifetime = min($this->config->getAccessTokenLifetime(), max(0, $familyExpiresAt - $now));
        $accessToken = $this->secret->generate(Secret::PREFIX_ACCESS);
        $refreshToken = $this->secret->generate(Secret::PREFIX_REFRESH);

        $this->storage->insertToken([
            'family_id' => $familyId,
            'client_id' => (string) $client['client_id'],
            'admin_user_id' => $adminUserId,
            'authorization_id' => $authorizationId,
            'scope' => $this->scope->format($scopes),
            'resource' => $this->endpoints->getResource(),
            'access_hash' => $this->secret->hash($accessToken),
            'access_expires_at' => $now + $lifetime,
            'refresh_hash' => $this->secret->hash($refreshToken),
            'refresh_expires_at' => $familyExpiresAt,
            'rotated' => 0,
            'revoked' => 0,
            'created_at' => $now,
        ]);

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $lifetime,
            'refresh_token' => $refreshToken,
            'scope' => $this->scope->format($scopes),
        ];
    }

    /**
     * A spent refresh token came back: revoke the grant and say so in the log.
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function revokeReplayedFamily(array $row): void
    {
        $revoked = $this->storage->revokeFamily((string) $row['family_id']);
        $this->auditLogger->warning('[magenx-mcp] oauth refresh token replayed; grant revoked', [
            'client_id' => $row['client_id'],
            'client_name' => $row['client_name'] ?? null,
            'admin_user_id' => $row['admin_user_id'],
            'revoked' => $revoked,
        ]);
    }

    /**
     * An admin who was deactivated, or is locked out after failed sign-ins, acts through no connection.
     *
     * @param array<string, mixed> $row
     * @param int $now
     * @return bool
     */
    private function isAdminUsable(array $row, int $now): bool
    {
        if ((int) $row['admin_is_active'] !== 1) {
            return false;
        }

        $lockExpires = $row['admin_lock_expires'] ?? null;

        return $lockExpires === null || $lockExpires === '' || (int) strtotime($lockExpires . ' UTC') <= $now;
    }

    /**
     * A resource indicator, if sent, must name this server.
     *
     * @param array<string, mixed> $params
     * @return void
     * @throws OAuthException
     */
    private function assertResource(array $params): void
    {
        $resource = $this->stringParam($params, 'resource');
        if ($resource !== '' && !$this->endpoints->isOwnResource($resource)) {
            throw new OAuthException('invalid_target', 'The resource parameter does not name this MCP server.');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param string $key
     * @return string
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
