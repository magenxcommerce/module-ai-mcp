<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every URL the authorization flow publishes or redirects to.
 *
 * The issuer and the protected resource have to be stable, exact strings:
 * a client compares the issuer it was sent against the metadata it fetched,
 * and a token is bound to the resource URL it was issued for. They are built
 * from one base — the configured public base URL, or the default store's
 * secure base URL — and nowhere else.
 *
 * The issuer carries the `/magenx-mcp` path so its RFC 8414 metadata lives at
 * /.well-known/oauth-authorization-server/magenx-mcp, namespaced away from
 * anything else on the host that might want the root document.
 */
class Endpoints
{
    public const ISSUER_PATH = '/magenx-mcp';
    public const RESOURCE_PATH = '/magenx-mcp/server';
    public const PROTECTED_RESOURCE_WELL_KNOWN = '/.well-known/oauth-protected-resource';
    public const AUTHORIZATION_SERVER_WELL_KNOWN = '/.well-known/oauth-authorization-server';

    /**
     * @param OAuthConfig $config
     * @param StoreManagerInterface $storeManager
     * @param BackendUrl $backendUrl
     */
    public function __construct(
        private readonly OAuthConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * Scheme and host (and port) every published URL starts with, no trailing slash.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        $configured = $this->config->getPublicBaseUrl();
        if ($configured !== '') {
            return $configured;
        }

        $base = (string) $this->storeManager->getDefaultStoreView()?->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);

        // Only the origin: a store whose base URL carries a path (a store code
        // in the URL, say) still serves this module from the root.
        if (preg_match('#^(https?://[^/]+)#i', $base, $matches) === 1) {
            return $matches[1];
        }

        return rtrim($base, '/');
    }

    /**
     * RFC 8414 issuer identifier.
     *
     * @return string
     */
    public function getIssuer(): string
    {
        return $this->getBaseUrl() . self::ISSUER_PATH;
    }

    /**
     * Canonical URI of the MCP endpoint, the RFC 8707 resource tokens are bound to.
     *
     * @return string
     */
    public function getResource(): string
    {
        return $this->getBaseUrl() . self::RESOURCE_PATH;
    }

    /**
     * RFC 9728 metadata URL, as advertised in WWW-Authenticate.
     *
     * @return string
     */
    public function getProtectedResourceMetadataUrl(): string
    {
        return $this->getBaseUrl() . self::PROTECTED_RESOURCE_WELL_KNOWN . self::RESOURCE_PATH;
    }

    /**
     * RFC 8414 metadata URL.
     *
     * @return string
     */
    public function getAuthorizationServerMetadataUrl(): string
    {
        return $this->getBaseUrl() . self::AUTHORIZATION_SERVER_WELL_KNOWN . self::ISSUER_PATH;
    }

    /**
     * @return string
     */
    public function getAuthorizationEndpoint(): string
    {
        return $this->getIssuer() . '/oauth/authorize';
    }

    /**
     * @return string
     */
    public function getTokenEndpoint(): string
    {
        return $this->getIssuer() . '/oauth/token';
    }

    /**
     * @return string
     */
    public function getRegistrationEndpoint(): string
    {
        return $this->getIssuer() . '/oauth/register';
    }

    /**
     * @return string
     */
    public function getRevocationEndpoint(): string
    {
        return $this->getIssuer() . '/oauth/revoke';
    }

    /**
     * The admin consent page for a pending request.
     *
     * Built without a secret key: the browser arriving here comes from
     * another site and cannot carry one. The consent action accepts that for
     * GET, which only displays the request; the decision is a POST, and that
     * is protected by the admin form key like any other.
     *
     * @param string $requestId
     * @return string
     */
    public function getConsentUrl(string $requestId): string
    {
        return $this->backendUrl->getUrl(
            'magenx_aimcp/oauth/consent',
            ['_nosecret' => true, 'request' => $requestId]
        );
    }

    /**
     * Whether a client-supplied resource indicator names this server.
     *
     * A trailing slash is the one difference tolerated: clients disagree on
     * it, and it cannot point anywhere else.
     *
     * @param string $resource
     * @return bool
     */
    public function isOwnResource(string $resource): bool
    {
        return rtrim($resource, '/') === $this->getResource();
    }

    /**
     * The RFC 8414 metadata document.
     *
     * @return array<string, mixed>
     */
    public function getAuthorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->getIssuer(),
            'authorization_endpoint' => $this->getAuthorizationEndpoint(),
            'token_endpoint' => $this->getTokenEndpoint(),
            'registration_endpoint' => $this->getRegistrationEndpoint(),
            'revocation_endpoint' => $this->getRevocationEndpoint(),
            'scopes_supported' => Scope::SUPPORTED,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ClientRegistry::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => ClientRegistry::AUTH_METHODS,
            'code_challenge_methods_supported' => [Pkce::METHOD_S256],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * The RFC 9728 metadata document.
     *
     * @return array<string, mixed>
     */
    public function getProtectedResourceMetadata(): array
    {
        return [
            'resource' => $this->getResource(),
            'authorization_servers' => [$this->getIssuer()],
            'scopes_supported' => Scope::SUPPORTED,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Magento AI MCP Server',
        ];
    }
}
