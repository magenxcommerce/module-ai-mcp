<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Psr\Log\LoggerInterface;

/**
 * The authorization request, from the client's redirect to the admin's decision.
 *
 * The public authorize endpoint only validates and parks the request; nothing
 * is granted there. The grant is made by a signed-in admin on the consent
 * page, behind the admin login and its two-factor check, and is never wider
 * than what the client asked for or what that admin's own role allows.
 */
class AuthorizationService
{
    /** How long an admin has to sign in and decide. */
    public const REQUEST_LIFETIME = 600;

    /** How long a code may wait before the client redeems it. */
    public const CODE_LIFETIME = 120;

    private const MAX_STATE_LENGTH = 2048;

    /**
     * @param Storage $storage
     * @param ClientRegistry $clientRegistry
     * @param RedirectUriPolicy $redirectUriPolicy
     * @param Endpoints $endpoints
     * @param Pkce $pkce
     * @param Scope $scope
     * @param Secret $secret
     * @param CoreDate $date
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly ClientRegistry $clientRegistry,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly Endpoints $endpoints,
        private readonly Pkce $pkce,
        private readonly Scope $scope,
        private readonly Secret $secret,
        private readonly CoreDate $date,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * Validate an authorization request and park it for the admin.
     *
     * Errors that leave the redirect URI untrusted (unknown client, a
     * callback it did not register) are thrown with no redirect target: they
     * must be shown to the user, never sent to the URI in question. Every
     * later error carries the redirect for the client to receive.
     *
     * @param array<string, mixed> $params The query string.
     * @return string The request id.
     * @throws OAuthException Without a redirect target.
     * @throws RedirectableOAuthException
     */
    public function createRequest(array $params): string
    {
        $client = $this->clientRegistry->find($this->stringParam($params, 'client_id'));
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Unknown client. Remove the connector and add it again.');
        }

        $registered = $this->clientRegistry->getRedirectUris($client);
        $redirectUri = $this->stringParam($params, 'redirect_uri');
        if ($redirectUri === '' && count($registered) === 1) {
            $redirectUri = $registered[0];
        }
        // Re-checked against the policy as well as the registration, so an
        // operator who takes a callback off the allowlist cuts off clients
        // that registered it earlier.
        if (!in_array($redirectUri, $registered, true) || !$this->redirectUriPolicy->isAllowed($redirectUri)) {
            throw new OAuthException('invalid_request', 'The redirect URI does not match this client\'s registration.');
        }

        $state = $this->stringParam($params, 'state');
        $issuer = $this->endpoints->getIssuer();
        $fail = static fn (string $error, string $description): RedirectableOAuthException
            => new RedirectableOAuthException($error, $description, $redirectUri, $state, $issuer);

        if (strlen($state) > self::MAX_STATE_LENGTH) {
            throw $fail('invalid_request', 'The state parameter is too long.');
        }
        if ($this->stringParam($params, 'response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }

        $challenge = $this->stringParam($params, 'code_challenge');
        if ($this->stringParam($params, 'code_challenge_method') !== Pkce::METHOD_S256
            || !$this->pkce->isValidChallenge($challenge)
        ) {
            throw $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $resource = $this->stringParam($params, 'resource');
        if ($resource !== '' && !$this->endpoints->isOwnResource($resource)) {
            throw $fail('invalid_target', 'The resource parameter does not name this MCP server.');
        }

        try {
            $scopes = $this->scope->parse($this->stringParam($params, 'scope'));
        } catch (OAuthException $e) {
            throw $fail($e->getError(), $e->getMessage());
        }

        $now = (int) $this->date->gmtTimestamp();
        $requestId = $this->secret->handle();
        $this->storage->insertAuthorization([
            'request_id' => $requestId,
            'client_id' => (string) $client['client_id'],
            'redirect_uri' => $redirectUri,
            'requested_scope' => $this->scope->format($scopes),
            'state' => $state === '' ? null : $state,
            'code_challenge' => $challenge,
            'resource' => $this->endpoints->getResource(),
            'status' => Storage::STATUS_PENDING,
            'created_at' => $now,
            'expires_at' => $now + self::REQUEST_LIFETIME,
        ]);

        return $requestId;
    }

    /**
     * A request still waiting for a decision, or null when it is unknown, decided or expired.
     *
     * @param string $requestId
     * @return array<string, mixed>|null
     */
    public function getPending(string $requestId): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $requestId) !== 1) {
            return null;
        }

        $request = $this->storage->getAuthorization($requestId);
        if ($request === null
            || $request['status'] !== Storage::STATUS_PENDING
            || (int) $request['expires_at'] < (int) $this->date->gmtTimestamp()
        ) {
            return null;
        }

        return $request;
    }

    /**
     * The scopes the client asked for.
     *
     * @param array<string, mixed> $request
     * @return string[]
     */
    public function getRequestedScopes(array $request): array
    {
        return $this->scope->normalize(explode(' ', (string) $request['requested_scope']));
    }

    /**
     * Approve a pending request on behalf of an admin and return the URL to send the browser to.
     *
     * @param string $requestId
     * @param int $adminUserId
     * @param string $adminLabel For the audit log.
     * @param bool $grantWrite Whether the admin ticked write access.
     * @return string
     * @throws OAuthException When the request is no longer pending.
     */
    public function approve(string $requestId, int $adminUserId, string $adminLabel, bool $grantWrite): string
    {
        $request = $this->getPending($requestId);
        if ($request === null) {
            throw new OAuthException('invalid_request', 'This request has expired or was already answered.');
        }

        $requested = $this->getRequestedScopes($request);
        $granted = $grantWrite && in_array(Scope::WRITE, $requested, true)
            ? [Scope::READ, Scope::WRITE]
            : [Scope::READ];

        $now = (int) $this->date->gmtTimestamp();
        $code = $this->secret->generate(Secret::PREFIX_CODE);
        $changed = $this->storage->transitionAuthorization($requestId, Storage::STATUS_PENDING, [
            'status' => Storage::STATUS_APPROVED,
            'admin_user_id' => $adminUserId,
            'granted_scope' => $this->scope->format($granted),
            'code_hash' => $this->secret->hash($code),
            'code_expires_at' => $now + self::CODE_LIFETIME,
        ]);
        if (!$changed) {
            throw new OAuthException('invalid_request', 'This request was already answered.');
        }

        $this->auditLogger->info('[magenx-mcp] oauth grant approved', [
            'admin' => $adminLabel,
            'admin_user_id' => $adminUserId,
            'client_id' => $request['client_id'],
            'client_name' => $request['client_name'],
            'redirect_uri' => $request['redirect_uri'],
            'scope' => $this->scope->format($granted),
        ]);

        return $this->redirectWith((string) $request['redirect_uri'], [
            'code' => $code,
            'state' => $request['state'],
            'iss' => $this->endpoints->getIssuer(),
        ]);
    }

    /**
     * Deny a pending request and return the URL to send the browser to.
     *
     * @param string $requestId
     * @param string $adminLabel
     * @return string|null Null when the request was no longer pending.
     */
    public function deny(string $requestId, string $adminLabel): ?string
    {
        $request = $this->getPending($requestId);
        if ($request === null
            || !$this->storage->transitionAuthorization(
                $requestId,
                Storage::STATUS_PENDING,
                ['status' => Storage::STATUS_DENIED]
            )
        ) {
            return null;
        }

        $this->auditLogger->info('[magenx-mcp] oauth grant denied', [
            'admin' => $adminLabel,
            'client_id' => $request['client_id'],
            'client_name' => $request['client_name'],
        ]);

        return $this->redirectWith((string) $request['redirect_uri'], [
            'error' => 'access_denied',
            'error_description' => 'The administrator declined the request.',
            'state' => $request['state'],
            'iss' => $this->endpoints->getIssuer(),
        ]);
    }

    /**
     * Append query parameters to a redirect URI, keeping any it already has.
     *
     * @param string $uri
     * @param array<string, mixed> $params Null values are left out.
     * @return string
     */
    public function redirectWith(string $uri, array $params): string
    {
        $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A query parameter as a string; arrays and other shapes read as absent.
     *
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
