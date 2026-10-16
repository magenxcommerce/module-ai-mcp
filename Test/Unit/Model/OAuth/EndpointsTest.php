<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The discovery documents and the URLs inside them.
 *
 * A client compares the issuer exactly and binds tokens to the resource, so
 * a slash out of place here is a connector that silently never works.
 *
 * @see Endpoints
 */
class EndpointsTest extends OAuthTestCase
{
    /**
     * @return void
     */
    public function testWellKnownUrlsFollowRfc8414AndRfc9728(): void
    {
        $this->assertSame('https://shop.example/magenx-mcp', $this->endpoints->getIssuer());
        $this->assertSame('https://shop.example/magenx-mcp/server', $this->endpoints->getResource());
        $this->assertSame(
            'https://shop.example/.well-known/oauth-authorization-server/magenx-mcp',
            $this->endpoints->getAuthorizationServerMetadataUrl()
        );
        $this->assertSame(
            'https://shop.example/.well-known/oauth-protected-resource/magenx-mcp/server',
            $this->endpoints->getProtectedResourceMetadataUrl()
        );
    }

    /**
     * @return void
     */
    public function testAuthorizationServerMetadata(): void
    {
        $metadata = $this->endpoints->getAuthorizationServerMetadata();

        $this->assertSame($this->endpoints->getIssuer(), $metadata['issuer']);
        $this->assertSame('https://shop.example/magenx-mcp/oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertSame('https://shop.example/magenx-mcp/oauth/token', $metadata['token_endpoint']);
        $this->assertSame('https://shop.example/magenx-mcp/oauth/register', $metadata['registration_endpoint']);
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        $this->assertSame(['code'], $metadata['response_types_supported']);
        $this->assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
    }

    /**
     * @return void
     */
    public function testProtectedResourceMetadataPointsAtTheIssuer(): void
    {
        $metadata = $this->endpoints->getProtectedResourceMetadata();

        $this->assertSame('https://shop.example/magenx-mcp/server', $metadata['resource']);
        $this->assertSame(['https://shop.example/magenx-mcp'], $metadata['authorization_servers']);
    }

    /**
     * @return void
     */
    public function testOwnResourceTolerantOfTrailingSlashOnly(): void
    {
        $this->assertTrue($this->endpoints->isOwnResource('https://shop.example/magenx-mcp/server'));
        $this->assertTrue($this->endpoints->isOwnResource('https://shop.example/magenx-mcp/server/'));
        $this->assertFalse($this->endpoints->isOwnResource('https://shop.example/magenx-mcp'));
        $this->assertFalse($this->endpoints->isOwnResource('https://evil.example/magenx-mcp/server'));
    }

    /**
     * Without a configured public URL the store's own secure base URL is used, origin only.
     *
     * @return void
     */
    public function testFallsBackToTheDefaultStoreOrigin(): void
    {
        $config = $this->createMock(OAuthConfig::class);
        $config->method('getPublicBaseUrl')->willReturn('');
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://www.example.com/en/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $endpoints = new Endpoints($config, $storeManager, $this->createMock(BackendUrl::class));

        $this->assertSame('https://www.example.com/magenx-mcp/server', $endpoints->getResource());
    }

    /**
     * @return void
     */
    public function testConsentLinkCarriesNoSecretKey(): void
    {
        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->expects($this->once())->method('getUrl')
            ->with('magenx_aimcp/oauth/consent', ['_nosecret' => true, 'request' => 'abc'])
            ->willReturn('https://shop.example/admin/magenx_aimcp/oauth/consent/request/abc/');

        $endpoints = new Endpoints(
            $this->createMock(OAuthConfig::class),
            $this->createMock(StoreManagerInterface::class),
            $backendUrl
        );

        $this->assertStringEndsWith('/request/abc/', $endpoints->getConsentUrl('abc'));
    }
}
