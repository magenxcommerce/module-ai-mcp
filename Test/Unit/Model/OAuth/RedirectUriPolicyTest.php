<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\RedirectUriPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist is the whole reason open registration is safe, so its matching is pinned exactly.
 *
 * @see RedirectUriPolicy
 */
class RedirectUriPolicyTest extends TestCase
{
    /**
     * @param string $uri
     * @param bool $loopback
     * @param bool $expected
     * @return void
     */
    #[DataProvider('uriProvider')]
    public function testIsAllowed(string $uri, bool $loopback, bool $expected): void
    {
        $config = $this->createMock(OAuthConfig::class);
        $config->method('getAllowedRedirectUris')->willReturn(['https://claude.ai/api/mcp/auth_callback']);
        $config->method('isLoopbackAllowed')->willReturn($loopback);

        $this->assertSame($expected, (new RedirectUriPolicy($config))->isAllowed($uri));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function uriProvider(): array
    {
        return [
            'listed' => ['https://claude.ai/api/mcp/auth_callback', false, true],
            'listed plus path' => ['https://claude.ai/api/mcp/auth_callback/x', false, false],
            'listed plus query' => ['https://claude.ai/api/mcp/auth_callback?a=1', false, false],
            'listed plus fragment' => ['https://claude.ai/api/mcp/auth_callback#x', false, false],
            'trailing slash' => ['https://claude.ai/api/mcp/auth_callback/', false, false],
            'case differs' => ['https://Claude.ai/api/mcp/auth_callback', false, false],
            'lookalike host' => ['https://claude.ai.evil.example/api/mcp/auth_callback', false, false],
            'userinfo trick' => ['https://claude.ai@evil.example/api/mcp/auth_callback', false, false],
            'empty' => ['', false, false],
            'loopback while off' => ['http://localhost:33418/callback', false, false],
            'localhost any port' => ['http://localhost:33418/callback', true, true],
            'ipv4 loopback' => ['http://127.0.0.1:6274/oauth/callback', true, true],
            'ipv6 loopback' => ['http://[::1]:8080/cb', true, true],
            'loopback no port' => ['http://localhost/cb', true, true],
            'loopback lookalike' => ['http://localhost.evil.example/cb', true, false],
            'loopback with userinfo' => ['http://localhost@evil.example/cb', true, false],
            'loopback over https is not loopback' => ['https://localhost:8080/cb', true, false],
            'loopback with query' => ['http://localhost:1/cb?x=1', true, false],
            'control character' => ["https://claude.ai/api/mcp/auth_callback\n", false, false],
        ];
    }

    /**
     * @return void
     */
    public function testDescribeShowsTheHost(): void
    {
        $policy = new RedirectUriPolicy($this->createMock(OAuthConfig::class));

        $this->assertSame('claude.ai', $policy->describe('https://claude.ai/api/mcp/auth_callback'));
        $this->assertSame('localhost:1234', $policy->describe('http://localhost:1234/cb'));
    }
}
