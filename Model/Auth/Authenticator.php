<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Auth;

use Laminas\Http\Header\HeaderInterface;
use Magenx\AiMcp\Model\Config;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Helper\Oauth\Data as OauthHelper;
use Magento\Integration\Model\Oauth\TokenFactory;

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
 */
class Authenticator
{
    private const BEARER_PREFIX = 'Bearer ';

    /**
     * @param TokenFactory $tokenFactory
     * @param IntegrationServiceInterface $integrationService
     * @param AclRetriever $aclRetriever
     * @param OauthHelper $oauthHelper
     * @param CoreDate $date
     * @param RemoteAddress $remoteAddress
     * @param Config $config
     */
    public function __construct(
        private readonly TokenFactory $tokenFactory,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly AclRetriever $aclRetriever,
        private readonly OauthHelper $oauthHelper,
        private readonly CoreDate $date,
        private readonly RemoteAddress $remoteAddress,
        private readonly Config $config
    ) {
    }

    /**
     * Authenticate the request or throw.
     *
     * @param HttpRequest $request
     * @return Identity
     * @throws AuthenticationException
     */
    public function authenticate(HttpRequest $request): Identity
    {
        $this->assertSourceAddressAllowed();

        $token = $this->readBearerToken($request);
        if ($token === null) {
            throw new AuthenticationException(__('A bearer token is required.'));
        }

        $tokenModel = $this->tokenFactory->create()->loadByToken($token);
        if (!$tokenModel->getId() || (int) $tokenModel->getRevoked() === 1) {
            throw new AuthenticationException(__('The access token is invalid or has been revoked.'));
        }

        $userType = (int) $tokenModel->getUserType();

        if ($userType === UserContextInterface::USER_TYPE_INTEGRATION) {
            $integration = $this->integrationService->findByConsumerId((int) $tokenModel->getConsumerId());
            $integrationId = (int) $integration->getId();
            if ($integrationId === 0) {
                throw new AuthenticationException(__('The access token is not bound to an integration.'));
            }

            return $this->buildIdentity(
                UserContextInterface::USER_TYPE_INTEGRATION,
                $integrationId,
                (string) ($integration->getName() ?: 'integration#' . $integrationId)
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
        throw new AuthenticationException(__('This token type cannot be used with the MCP endpoint.'));
    }

    /**
     * Resolve the caller's ACL grants.
     *
     * @param int $userType
     * @param int $userId
     * @param string $label
     * @return Identity
     * @throws AuthenticationException
     */
    private function buildIdentity(int $userType, int $userId, string $label): Identity
    {
        if ($userId === 0) {
            throw new AuthenticationException(__('The access token is not bound to a user.'));
        }

        try {
            $resources = $this->aclRetriever->getAllowedResourcesByUser($userType, $userId);
        } catch (\Throwable) {
            // No role, or a role that resolves to nothing: grant nothing.
            $resources = [];
        }

        return new Identity($userType, $userId, $label, $resources);
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
            throw new AuthenticationException(
                __('The admin token has expired. Use an Integration access token, which does not expire.')
            );
        }
    }

    /**
     * Enforce the optional source-address allowlist.
     *
     * @return void
     * @throws AuthenticationException
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

        throw new AuthenticationException(__('This source address is not permitted.'));
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

        [$subnet, $bits] = explode('/', $candidate, 2);
        $remoteBinary = @inet_pton($remote);
        $subnetBinary = @inet_pton($subnet);
        if ($remoteBinary === false || $subnetBinary === false
            || strlen($remoteBinary) !== strlen($subnetBinary)
        ) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($remoteBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) {
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
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (!str_starts_with($header, self::BEARER_PREFIX)) {
            return null;
        }

        $token = trim(substr($header, strlen(self::BEARER_PREFIX)));

        return $token === '' ? null : $token;
    }
}
