<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\DomainList;
use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\Pkce;
use Magenx\AiMcp\Model\OAuth\RedirectUriPolicy;
use Magenx\AiMcp\Model\OAuth\Scope;
use Magenx\AiMcp\Model\OAuth\Secret;
use Magenx\AiMcp\Model\OAuth\TokenService;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Real OAuth services over {@see FakeStorage}, a movable clock and fixed configuration.
 */
abstract class OAuthTestCase extends TestCase
{
    protected const BASE = 'https://shop.example';
    protected const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    /** RFC 7636 Appendix B. */
    protected const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    protected const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected FakeStorage $storage;
    protected int $now = 1_800_000_000;
    protected OAuthConfig&MockObject $oauthConfig;
    protected Endpoints $endpoints;
    protected ClientRegistry $clients;
    protected AuthorizationService $authorizations;
    protected TokenService $tokens;
    protected LoggerInterface&MockObject $logger;

    /** @var string[] */
    protected array $allowedRedirects = [self::CALLBACK];
    protected bool $loopback = false;
    protected bool $dynamicRegistration = true;
    protected string $connectorKeyHash = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->storage = new FakeStorage();
        $this->logger = $this->createMock(LoggerInterface::class);

        $date = $this->createMock(CoreDate::class);
        $date->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $this->oauthConfig = $this->createMock(OAuthConfig::class);
        $this->oauthConfig->method('isEnabled')->willReturn(true);
        $this->oauthConfig->method('getAllowedRedirectUris')
            ->willReturnCallback(fn (): array => $this->allowedRedirects);
        $this->oauthConfig->method('isLoopbackAllowed')->willReturnCallback(fn (): bool => $this->loopback);
        $this->oauthConfig->method('isDynamicRegistrationAllowed')
            ->willReturnCallback(fn (): bool => $this->dynamicRegistration);
        $this->oauthConfig->method('getConnectorKeyHash')->willReturnCallback(fn (): string => $this->connectorKeyHash);
        $this->oauthConfig->method('getAccessTokenLifetime')->willReturn(3600);
        $this->oauthConfig->method('getRefreshTokenLifetime')->willReturn(30 * 86400);
        $this->oauthConfig->method('getPublicBaseUrl')->willReturn(self::BASE);

        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturnCallback(
            static fn ($route, $params): string
                => self::BASE . '/admin/' . $route . '/request/' . $params['request'] . '/'
        );
        $this->endpoints = new Endpoints(
            $this->oauthConfig,
            $this->createMock(StoreManagerInterface::class),
            $backendUrl
        );

        $policy = new RedirectUriPolicy($this->oauthConfig);
        $secret = new Secret();
        $scope = new Scope();
        $pkce = new Pkce();

        $domainList = new DomainList();
        $this->clients = new ClientRegistry(
            $this->storage,
            $this->oauthConfig,
            $policy,
            $secret,
            $date,
            $this->logger,
            $domainList
        );
        $this->authorizations = new AuthorizationService(
            $this->storage,
            $this->oauthConfig,
            $this->clients,
            $policy,
            $this->endpoints,
            $pkce,
            $scope,
            $secret,
            $date,
            $this->logger,
            $domainList
        );
        $this->tokens = new TokenService(
            $this->storage,
            $this->oauthConfig,
            $this->endpoints,
            $pkce,
            $scope,
            $secret,
            $date,
            $this->logger,
            $domainList
        );
    }

    /**
     * Register a public client with the default callback.
     *
     * @param string $authMethod
     * @return array<string, mixed> The registration response.
     */
    protected function registerClient(string $authMethod = ClientRegistry::AUTH_NONE): array
    {
        return $this->clients->register(
            [
                'client_name' => 'Claude',
                'redirect_uris' => [self::CALLBACK],
                'token_endpoint_auth_method' => $authMethod,
            ],
            '203.0.113.7'
        );
    }

    /**
     * A valid authorization request for the client.
     *
     * @param string $clientId
     * @param array<string, string> $override
     * @return array<string, string>
     */
    protected function authorizeParams(string $clientId, array $override = []): array
    {
        return $override + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'mcp:read mcp:write',
            'resource' => self::BASE . '/magenx-mcp/server',
        ];
    }

    /**
     * Run a client through registration, authorization and approval; return the code.
     *
     * @param bool $grantWrite
     * @return array{client: array<string, mixed>, code: string}
     */
    protected function approvedCode(bool $grantWrite = true): array
    {
        $registration = $this->registerClient();
        $requestId = $this->authorizations->createRequest($this->authorizeParams($registration['client_id']));
        $redirect = $this->authorizations->approve($requestId, 7, 'admin:alice', $grantWrite);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return ['client' => $this->clients->find($registration['client_id']), 'code' => (string) $query['code']];
    }
}
