<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Psr\Log\LoggerInterface;

/**
 * OAuth clients: created by an administrator, or registered by anyone (RFC 7591)
 * where the operator allows it; and client authentication at the token endpoint.
 *
 * Every redirect URI has to pass {@see RedirectUriPolicy}, so a client can only
 * ever receive codes at a callback the operator listed. A client is a name and
 * a set of callbacks — it carries no privilege. Privilege comes from the admin
 * who approves it, and is that admin's own role.
 *
 * That does not make open registration harmless. The listed callback is
 * claude.ai's, where anyone has an account: a stranger can register, start a
 * flow, learn the admin URL from the redirect, and send an admin the consent
 * link — which, approved, connects the stranger's Claude. So by default only
 * a client an administrator created may start a flow, and such a client is
 * always confidential: without its secret a code cannot be redeemed, whoever
 * talked an admin into approving it.
 *
 * A client an administrator created also carries a policy: which admins may
 * approve it, which tool domains it may ever use, what a second approval by
 * the same admin does, and whether it is disabled. Disabling keeps its
 * connections, so enabling it again restores them without anyone signing in.
 */
class ClientRegistry
{
    public const AUTH_NONE = 'none';
    public const AUTH_POST = 'client_secret_post';
    public const AUTH_BASIC = 'client_secret_basic';

    /** @var string[] */
    public const AUTH_METHODS = [self::AUTH_NONE, self::AUTH_POST, self::AUTH_BASIC];

    private const MAX_REDIRECT_URIS = 5;
    private const MAX_NAME_LENGTH = 100;

    /**
     * @param Storage $storage
     * @param OAuthConfig $config
     * @param RedirectUriPolicy $redirectUriPolicy
     * @param Secret $secret
     * @param CoreDate $date
     * @param LoggerInterface $auditLogger
     * @param DomainList $domainList
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly OAuthConfig $config,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly Secret $secret,
        private readonly CoreDate $date,
        private readonly LoggerInterface $auditLogger,
        private readonly DomainList $domainList
    ) {
    }

    /**
     * Register a client from its RFC 7591 metadata and return the registration response.
     *
     * @param array<string, mixed> $metadata
     * @param string $remoteAddress For the audit log.
     * @return array<string, mixed>
     * @throws OAuthException
     */
    public function register(array $metadata, string $remoteAddress): array
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthException(
                'invalid_redirect_uri',
                sprintf('Provide between 1 and %d redirect_uris.', self::MAX_REDIRECT_URIS)
            );
        }
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !$this->redirectUriPolicy->isAllowed($uri)) {
                $this->auditLogger->warning('[magenx-mcp] oauth client registration refused', [
                    'remote_address' => $remoteAddress,
                    'redirect_uri' => is_string($uri) ? substr($uri, 0, 200) : '(not a string)',
                ]);

                throw new OAuthException(
                    'invalid_redirect_uri',
                    'This redirect URI is not on the server\'s allowlist. An administrator can add it under '
                    . 'Stores > Configuration > Magenx > AI MCP Server > OAuth.'
                );
            }
        }
        $redirectUris = array_values(array_unique($redirectUris));

        $grantTypes = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grantTypes) || array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthException(
                'invalid_client_metadata',
                'Only authorization_code and refresh_token grants are supported.'
            );
        }
        $responseTypes = $metadata['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || array_diff($responseTypes, ['code']) !== []) {
            throw new OAuthException('invalid_client_metadata', 'Only the "code" response type is supported.');
        }

        // RFC 7591 §2: omitted means client_secret_basic, not none.
        $authMethod = $metadata['token_endpoint_auth_method'] ?? self::AUTH_BASIC;
        if (!is_string($authMethod) || !in_array($authMethod, self::AUTH_METHODS, true)) {
            throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }

        $name = $metadata['client_name'] ?? '';
        $name = is_string($name) ? trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '') : '';
        if ($name === '') {
            $name = $this->redirectUriPolicy->describe($redirectUris[0]);
        }
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        $now = (int) $this->date->gmtTimestamp();
        $clientId = $this->secret->generate(Secret::PREFIX_CLIENT);
        $clientSecret = $authMethod === self::AUTH_NONE ? null : $this->secret->generate(Secret::PREFIX_CLIENT_SECRET);

        $this->storage->insertClient([
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
            'auth_method' => $authMethod,
            'secret_hash' => $clientSecret === null ? null : $this->secret->hash($clientSecret),
            'granted' => 0,
            'created_at' => $now,
            'origin' => Storage::ORIGIN_DYNAMIC,
        ]);

        $this->auditLogger->info('[magenx-mcp] oauth client registered', [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
            'remote_address' => $remoteAddress,
        ]);

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => $now,
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => implode(' ', Scope::SUPPORTED),
        ];
        if ($clientSecret !== null) {
            $response['client_secret'] = $clientSecret;
            $response['client_secret_expires_at'] = 0;
        }

        return $response;
    }

    /**
     * Create a confidential client for an administrator to hand to an AI service.
     *
     * Its callbacks are every URI on the allowlist, so one client serves
     * claude.ai and claude.com alike. The secret is returned once and stored
     * only as a hash.
     *
     * @param string $name
     * @param string $adminLabel For the audit log.
     * @return array{client_id: string, client_secret: string}
     * @throws OAuthException When the allowlist names no callback.
     */
    public function createForAdmin(string $name, string $adminLabel): array
    {
        $redirectUris = array_values(array_filter(
            array_unique($this->config->getAllowedRedirectUris()),
            fn (string $uri): bool => $this->redirectUriPolicy->isAllowed($uri)
        ));
        if ($redirectUris === []) {
            throw new OAuthException(
                'invalid_redirect_uri',
                'Add the AI service\'s callback to Allowed Redirect URIs before creating a client.'
            );
        }

        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '');
        $name = mb_substr($name === '' ? 'Claude' : $name, 0, self::MAX_NAME_LENGTH);

        $clientId = $this->secret->generate(Secret::PREFIX_CLIENT);
        $clientSecret = $this->secret->generate(Secret::PREFIX_CLIENT_SECRET);

        $this->storage->insertClient([
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
            'auth_method' => self::AUTH_BASIC,
            'secret_hash' => $this->secret->hash($clientSecret),
            'granted' => 0,
            'created_at' => (int) $this->date->gmtTimestamp(),
            'origin' => Storage::ORIGIN_ADMIN,
        ]);

        $this->auditLogger->info('[magenx-mcp] oauth client created by admin', [
            'admin' => $adminLabel,
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
        ]);

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    /**
     * Whether this client may start an authorization flow.
     *
     * A dynamically registered client may not once registration is closed. Its
     * existing connections keep refreshing until they expire or are revoked.
     *
     * @param array<string, mixed> $client
     * @return bool
     */
    public function mayStartFlow(array $client): bool
    {
        return ($client['origin'] ?? Storage::ORIGIN_DYNAMIC) === Storage::ORIGIN_ADMIN
            || $this->config->isDynamicRegistrationAllowed();
    }

    /**
     * A registered client that is not disabled, or null.
     *
     * A disabled client reads exactly as an unknown one, at every endpoint
     * that looks a client up: the error a caller gets must not tell it that
     * the id it holds still exists and could work again.
     *
     * @param string $clientId
     * @return array<string, mixed>|null
     */
    public function find(string $clientId): ?array
    {
        if ($clientId === '' || !str_starts_with($clientId, Secret::PREFIX_CLIENT)) {
            return null;
        }

        $client = $this->storage->getClient($clientId);

        return $client === null || (int) ($client['disabled'] ?? 0) === 1 ? null : $client;
    }

    /**
     * Whether an admin may approve this client.
     *
     * An empty approver list leaves it to the admin's role, as before
     * approver lists existed.
     *
     * @param string $clientId
     * @param int $adminUserId
     * @return bool
     */
    public function isApprover(string $clientId, int $adminUserId): bool
    {
        $approvers = $this->storage->getClientApprovers($clientId);

        return $approvers === [] || in_array($adminUserId, $approvers, true);
    }

    /**
     * The tool domains a client may ever use, or null for every domain.
     *
     * @param array<string, mixed> $client A client row, or a row joined with one as `client_domains`.
     * @return string[]|null
     */
    public function getAllowedDomains(array $client): ?array
    {
        $stored = array_key_exists('client_domains', $client)
            ? $client['client_domains']
            : ($client['allowed_domains'] ?? null);

        return $this->domainList->decode($stored === null ? null : (string) $stored);
    }

    /**
     * Set the policy of a client an administrator created.
     *
     * Narrowing the approver list ends the connections of every admin taken
     * off it: the list says who may act through the client, not only who may
     * start a connection. Narrowing the domains needs nothing revoked — it is
     * applied to live connections at every call.
     *
     * @param string $clientId
     * @param int[] $approverIds Empty for any admin whose role allows approving.
     * @param string[]|null $domains Null for every domain.
     * @param string $reapproval One of Storage::REAPPROVAL_MODES.
     * @param string $adminLabel For the audit log.
     * @return void
     * @throws OAuthException When the client is unknown or a value is invalid.
     */
    public function updatePolicy(
        string $clientId,
        array $approverIds,
        ?array $domains,
        string $reapproval,
        string $adminLabel
    ): void {
        if (!in_array($reapproval, Storage::REAPPROVAL_MODES, true)) {
            throw new OAuthException('invalid_request', 'Unknown re-approval mode.');
        }
        if ($domains === []) {
            throw new OAuthException(
                'invalid_request',
                'Select at least one tool area, or none of them to allow every area.'
            );
        }

        $known = $this->storage->getActiveAdminUsers();
        $approverIds = array_values(array_unique(array_map('intval', $approverIds)));
        if (array_diff($approverIds, array_keys($known)) !== []) {
            throw new OAuthException('invalid_request', 'An approver is not an active administrator.');
        }

        if (!$this->storage->updateAdminClient($clientId, [
            'allowed_domains' => $this->domainList->encode($domains),
            'reapproval' => $reapproval,
        ])) {
            throw new OAuthException('invalid_client', 'That client was not found.');
        }

        $this->storage->setClientApprovers($clientId, $approverIds);
        $revoked = $approverIds === [] ? 0 : $this->storage->revokeClientGrantsExcept($clientId, $approverIds);

        $this->auditLogger->info('[magenx-mcp] oauth client policy changed', [
            'admin' => $adminLabel,
            'client_id' => $clientId,
            'approvers' => array_values(array_intersect_key($known, array_flip($approverIds))),
            'domains' => $domains,
            'reapproval' => $reapproval,
            'revoked' => $revoked,
        ]);
    }

    /**
     * Disable or enable a client an administrator created.
     *
     * Its connections are kept, and refused while it is disabled.
     *
     * @param string $clientId
     * @param bool $disabled
     * @param string $adminLabel
     * @return bool Whether the client exists.
     */
    public function setDisabled(string $clientId, bool $disabled, string $adminLabel): bool
    {
        if (!$this->storage->updateAdminClient($clientId, ['disabled' => $disabled ? 1 : 0])) {
            return false;
        }

        $this->auditLogger->info(
            $disabled ? '[magenx-mcp] oauth client disabled' : '[magenx-mcp] oauth client enabled',
            ['admin' => $adminLabel, 'client_id' => $clientId]
        );

        return true;
    }

    /**
     * Give a client an administrator created a new secret, returned once; the old one stops working at once.
     *
     * Connections survive: they belong to the admins who approved them, not
     * to the secret. The client cannot refresh until it holds the new one.
     *
     * @param string $clientId
     * @param string $adminLabel
     * @return string|null The new secret, or null when there is no such client.
     */
    public function rotateSecret(string $clientId, string $adminLabel): ?string
    {
        $clientSecret = $this->secret->generate(Secret::PREFIX_CLIENT_SECRET);
        if (!$this->storage->updateAdminClient($clientId, ['secret_hash' => $this->secret->hash($clientSecret)])) {
            return null;
        }

        $this->auditLogger->info('[magenx-mcp] oauth client secret rotated', [
            'admin' => $adminLabel,
            'client_id' => $clientId,
        ]);

        return $clientSecret;
    }

    /**
     * The client's registered redirect URIs.
     *
     * @param array<string, mixed> $client
     * @return string[]
     */
    public function getRedirectUris(array $client): array
    {
        $uris = json_decode((string) ($client['redirect_uris'] ?? '[]'), true);

        return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
    }

    /**
     * Authenticate a client at the token or revocation endpoint.
     *
     * The method used must be the one registered: a confidential client may
     * not drop down to presenting no secret. A client an administrator created
     * never registered a method, so it may present its secret either way.
     *
     * @param string|null $clientId
     * @param string|null $clientSecret
     * @param string $methodUsed One of AUTH_METHODS.
     * @return array<string, mixed>
     * @throws OAuthException
     */
    public function authenticate(?string $clientId, ?string $clientSecret, string $methodUsed): array
    {
        $client = $this->find((string) $clientId);
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }

        $registered = (string) $client['auth_method'];
        if ($registered === self::AUTH_NONE) {
            if ($methodUsed !== self::AUTH_NONE) {
                throw new OAuthException('invalid_client', 'This client was registered without a secret.', 401);
            }

            return $client;
        }

        $accepted = ($client['origin'] ?? Storage::ORIGIN_DYNAMIC) === Storage::ORIGIN_ADMIN
            ? [self::AUTH_POST, self::AUTH_BASIC]
            : [$registered];
        if (!in_array($methodUsed, $accepted, true) || $clientSecret === null || $clientSecret === ''
            || !hash_equals((string) $client['secret_hash'], $this->secret->hash($clientSecret))
        ) {
            throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }
}
