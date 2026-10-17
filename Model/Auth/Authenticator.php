<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Auth;

use Laminas\Http\Header\HeaderInterface;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\ConnectorKey;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\Secret;
use Magenx\AiMcp\Model\OAuth\TokenService;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Helper\Oauth\Data as OauthHelper;
use Magento\Integration\Model\Oauth\TokenFactory;
use Psr\Log\LoggerInterface;

/**
 * Turns the request's `Authorization: Bearer <token>` into an {@see Identity}.
 *
 * This is the entire authorization boundary of the MCP endpoint, so it fails
 * closed at every step. It deliberately reuses Magento's own token model and
 * ACL retriever rather than inventing a shared secret: an operator creates an
 * Integration in admin, ticks exactly the resources the agent should have, and
 * that role is what every tool is checked against.
 *
 * Integration tokens do not expire; admin-user tokens do, and their lifetime is
 * enforced here the same way the webapi framework enforces it.
 *
 * OAuth access tokens (the `mxa_` prefix) are the third kind, accepted only
 * while OAuth is switched on. They resolve to the admin who approved the
 * connection and are checked against that admin's role, exactly like an
 * admin-user token, narrowed further by the scope the admin granted. Where the
 * operator generated a {@see ConnectorKey}, they must also arrive with it.
 */
class Authenticator
{
    private const BEARER_PREFIX = 'Bearer ';

    /** \Magento\Integration\Model\Integration::STATUS_ACTIVE */
    private const INTEGRATION_STATUS_ACTIVE = 1;

    /**
     * @param TokenFactory $tokenFactory
     * @param IntegrationServiceInterface $integrationService
     * @param OauthHelper $oauthHelper
     * @param CoreDate $date
     * @param RemoteAddress $remoteAddress
     * @param Config $config
     * @param LoggerInterface $auditLogger
     * @param OAuthConfig $oauthConfig
     * @param TokenService $tokenService
     * @param AclResources $aclResources
     * @param Secret $secret
     */
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly OauthHelper $oauthHelper,
        private readonly CoreDate $date,
        private readonly RemoteAddress $remoteAddress,
        private readonly Config $config,
        private readonly LoggerInterface $auditLogger,
        private readonly OAuthConfig $oauthConfig,
        private readonly TokenService $tokenService,
        private readonly AclResources $aclResources,
        private readonly Secret $secret
    ) {
    }

    /**
     * Authenticate the request or throw.
     *
     * @param HttpRequest $request
     * @return Identity
     * @throws AuthenticationException
     * @throws AccessDeniedException
     */
    public function authenticate(HttpRequest $request): Identity
    {
        $this->assertSourceAddressAllowed();

        $token = $this->readBearerToken($request);
        if ($token === null) {
            throw $this->refuse(__('A bearer token is required.'));
        }

        if (str_starts_with($token, Secret::PREFIX_ACCESS)) {
            return $this->authenticateOAuth($token, $request);
        }

        $tokenModel = $this->tokenFactory->create()->loadByToken($token);
        if (!$tokenModel->getId() || (int) $tokenModel->getRevoked() === 1) {
            throw $this->refuse(__('The access token is invalid or has been revoked.'));
        }

        $userType = (int) $tokenModel->getUserType();

        if ($userType === UserContextInterface::USER_TYPE_INTEGRATION) {
            $integration = $this->integrationService->findByConsumerId((int) $tokenModel->getConsumerId());
            $integrationId = (int) $integration->getId();
            if ($integrationId === 0) {
                throw $this->refuse(__('The access token is not bound to an integration.'));
            }
            // Deactivating an integration in admin leaves its token in
            // oauth_token. Magento's own REST API refuses it from then on, and
            // so must this endpoint, or deactivation would not cut an agent off.
            if ((int) $integration->getStatus() !== self::INTEGRATION_STATUS_ACTIVE) {
                throw $this->refuse(__('The integration behind this token is not active.'));
            }

            $name = (string) ($integration->getName() ?: 'integration#' . $integrationId);

            return $this->buildIdentity(
                UserContextInterface::USER_TYPE_INTEGRATION,
                $integrationId,
                $name,
                true,
                $name
            );
        }

        if ($userType === UserContextInterface::USER_TYPE_ADMIN) {
            $this->assertAdminTokenFresh($tokenModel->getCreatedAt());

            return $this->buildIdentity(
                UserContextInterface::USER_TYPE_ADMIN,
                (int) $tokenModel->getAdminId(),
                'admin#' . (int) $tokenModel->getAdminId()
            );
        }

        // Customer tokens (and anything else) have no business here.
        throw $this->refuse(__('This token type cannot be used with the MCP endpoint.'));
    }

    /**
     * Resolve an OAuth access token to the admin who granted it.
     *
     * The token is checked before the connector key, so an expired token still
     * draws the 401 that makes the client refresh it: the token endpoint is
     * never sent the key, and a 403 there would strand the connection.
     *
     * @param string $token
     * @param HttpRequest $request
     * @return Identity
     * @throws AuthenticationException
     * @throws AccessDeniedException
     */
    private function authenticateOAuth(string $token, HttpRequest $request): Identity
    {
        if (!$this->oauthConfig->isEnabled()) {
            throw $this->refuse(__('OAuth access tokens are not accepted on this server.'));
        }

        $grant = $this->tokenService->resolve($token);
        if ($grant === null) {
            throw $this->refuse(__('The access token is invalid, expired or revoked.'));
        }

        $keyHash = $this->oauthConfig->getConnectorKeyHash();
        $raw = $request->getHeader(ConnectorKey::HEADER);
        $presented = trim($raw instanceof HeaderInterface ? $raw->getFieldValue() : (string) $raw);
        if ($keyHash !== '' && ($presented === '' || !hash_equals($keyHash, $this->secret->hash($presented)))) {
            $this->auditLogger->warning('[magenx-mcp] oauth connector key refused', [
                'remote_address' => (string) $this->remoteAddress->getRemoteAddress(),
                'admin' => $grant->getUsername(),
                'client_name' => $grant->getClientName(),
                'reason' => $presented === '' ? 'missing' : 'wrong',
            ]);

            // 403, not 401: a new token would not help, and a challenge would
            // send the client round the OAuth flow again for nothing.
            throw new AccessDeniedException(__(
                'This connection needs the store\'s connector key in the x-api-key request header. '
                . 'Add it in the connector\'s settings, or ask an administrator for the current one.'
            ));
        }

        return $this->buildIdentity(
            UserContextInterface::USER_TYPE_ADMIN,
            $grant->getAdminUserId(),
            sprintf('admin:%s via %s', $grant->getUsername(), $grant->getClientName()),
            $grant->allowsWrites(),
            $grant->getClientName(),
            $grant->getDomains()
        );
    }

    /**
     * Record a refusal and build the exception for it.
     *
     * Failures are logged to the same file as the writes, because a stream of
     * rejected tokens is exactly what an operator auditing this endpoint needs
     * to see. The token itself is never logged, in any form: a truncated secret
     * is still a secret, and the source address is what identifies the caller.
     *
     * Takes an already-built Phrase rather than a string to translate here, so
     * every message stays a literal at its call site where Magento's i18n
     * collector can find it.
     *
     * @param Phrase $reason
     * @return AuthenticationException
     */
    private function refuse(Phrase $reason): AuthenticationException
    {
        $this->auditLogger->warning('[magenx-mcp] authentication failed', [
            'remote_address' => (string) $this->remoteAddress->getRemoteAddress(),
            'reason' => $reason->render(),
        ]);

        return new AuthenticationException($reason);
    }

    /**
     * Resolve the caller's ACL grants.
     *
     * @param int $userType
     * @param int $userId
     * @param string $label
     * @param bool $writeScope
     * @param string|null $connector
     * @param string[]|null $domains
     * @return Identity
     * @throws AuthenticationException
     */
    private function buildIdentity(
        int $userType,
        int $userId,
        string $label,
        bool $writeScope = true,
        ?string $connector = null,
        ?array $domains = null
    ): Identity {
        if ($userId === 0) {
            throw $this->refuse(__('The access token is not bound to a user.'));
        }

        $resources = $this->aclResources->forUser($userType, $userId);

        return new Identity($userType, $userId, $label, $resources, $writeScope, $connector, $domains);
    }

    /**
     * Admin-user tokens expire; integration tokens do not.
     *
     * @param string|null $createdAt
     * @return void
     * @throws AuthenticationException
     */
    private function assertAdminTokenFresh(?string $createdAt): void
    {
        $lifetimeHours = (int) $this->oauthHelper->getAdminTokenLifetime();
        if ($lifetimeHours <= 0 || $createdAt === null || $createdAt === '') {
            return;
        }

        $expiresAt = strtotime($createdAt) + ($lifetimeHours * 3600);
        if ($expiresAt < $this->date->gmtTimestamp()) {
            throw $this->refuse(
                __('The admin token has expired. Use an Integration access token, which does not expire.')
            );
        }
    }

    /**
     * Enforce the optional source-address allowlist.
     *
     * Note that this compares against REMOTE_ADDR: Magento's RemoteAddress is
     * constructed with no alternative headers and no trusted proxies, so it
     * never consults X-Forwarded-For. Behind a reverse proxy that is the
     * proxy's address unless nginx's real_ip module is rewriting it — see the
     * README. The failure is closed, so a misconfigured allowlist locks the
     * endpoint rather than opening it, and the log line below is what makes
     * that diagnosable.
     *
     * @return void
     * @throws AccessDeniedException
     */
    private function assertSourceAddressAllowed(): void
    {
        $allowed = $this->config->getAllowedIps();
        if ($allowed === []) {
            return;
        }

        $remote = (string) $this->remoteAddress->getRemoteAddress();
        foreach ($allowed as $candidate) {
            if ($this->matchesAddress($remote, $candidate)) {
                return;
            }
        }

        $this->auditLogger->warning('[magenx-mcp] source address refused', [
            'remote_address' => $remote,
            'reason' => 'not matched by magenx_ai_mcp/security/allowed_ips',
        ]);

        throw new AccessDeniedException(__('This source address is not permitted.'));
    }

    /**
     * Match a remote address against a plain address or a CIDR range.
     *
     * @param string $remote
     * @param string $candidate
     * @return bool
     */
    private function matchesAddress(string $remote, string $candidate): bool
    {
        if ($remote === '') {
            return false;
        }

        if (!str_contains($candidate, '/')) {
            return $remote === $candidate;
        }

        [$subnet, $prefix] = explode('/', $candidate, 2);

        // A prefix that is not a plain number must refuse, not be cast: `(int)`
        // turns "10.0.0.0/" and "10.0.0.0/abc" into a /0, which matches every
        // address and would silently convert a typo in the allowlist into no
        // allowlist at all.
        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a malformed remote address must yield false, which is handled immediately below.
        $remoteBinary = @inet_pton($remote);
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a malformed subnet must yield false, which is handled immediately below.
        $subnetBinary = @inet_pton($subnet);
        if ($remoteBinary === false || $subnetBinary === false
            || strlen($remoteBinary) !== strlen($subnetBinary)
        ) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($remoteBinary) * 8;
        if ($bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($remoteBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainderBits)) - 1) & 0xFF;

        return (ord($remoteBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }

    /**
     * Read the bearer token from the Authorization header.
     *
     * `getHeader()` returns a string in some Magento versions and a Laminas
     * header object in others, and that object stringifies to the whole field
     * line ("Authorization: Bearer x"), so both shapes are normalised here.
     * The `$_SERVER` fallback covers Apache setups that hide the header from
     * PHP unless it is explicitly passed through.
     *
     * @param HttpRequest $request
     * @return string|null
     */
    private function readBearerToken(HttpRequest $request): ?string
    {
        $raw = $request->getHeader('Authorization');
        $header = $raw instanceof HeaderInterface ? $raw->getFieldValue() : (string) $raw;

        if ($header === '' || $header === '1') {
            // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning -- Apache/CGI strips Authorization from the request object; these are the only places it survives.
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (!str_starts_with($header, self::BEARER_PREFIX)) {
            return null;
        }

        $token = trim(substr($header, strlen(self::BEARER_PREFIX)));

        return $token === '' ? null : $token;
    }
}
