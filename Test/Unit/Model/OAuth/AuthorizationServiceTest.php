<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\RedirectableOAuthException;
use Magenx\AiMcp\Model\OAuth\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The authorize request and the admin's decision.
 *
 * The line that matters most: an error is only ever redirected once the
 * redirect URI is known to be the client's own registered, allowlisted one.
 *
 * @see AuthorizationService
 */
class AuthorizationServiceTest extends OAuthTestCase
{
    /**
     * @return void
     */
    public function testValidRequestIsParkedAsPending(): void
    {
        $clientId = $this->registerClient()['client_id'];

        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $requestId);
        $row = $this->storage->authorizations[$requestId];
        $this->assertSame(Storage::STATUS_PENDING, $row['status']);
        $this->assertSame('mcp:read mcp:write', $row['requested_scope']);
        $this->assertSame(self::CHALLENGE, $row['code_challenge']);
        $this->assertSame($this->now + AuthorizationService::REQUEST_LIFETIME, $row['expires_at']);
        $this->assertNotNull($this->authorizations->getPending($requestId));
    }

    /**
     * @return void
     */
    public function testUnknownClientIsNotRedirected(): void
    {
        try {
            $this->authorizations->createRequest($this->authorizeParams('mxclient_unknown'));
            $this->fail('An unknown client was accepted.');
        } catch (OAuthException $e) {
            $this->assertNotInstanceOf(RedirectableOAuthException::class, $e);
        }
    }

    /**
     * Open-redirect guard: a callback the client did not register is never redirected to.
     *
     * @return void
     */
    public function testUnregisteredRedirectIsNotRedirected(): void
    {
        $clientId = $this->registerClient()['client_id'];

        try {
            $this->authorizations->createRequest(
                $this->authorizeParams($clientId, ['redirect_uri' => 'https://evil.example/cb'])
            );
            $this->fail('An unregistered redirect URI was accepted.');
        } catch (OAuthException $e) {
            $this->assertNotInstanceOf(RedirectableOAuthException::class, $e);
        }
    }

    /**
     * Taking a callback off the allowlist cuts off clients that registered it earlier.
     *
     * @return void
     */
    public function testRedirectRemovedFromAllowlistAfterRegistrationIsRefused(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $this->allowedRedirects = [];

        $this->expectException(OAuthException::class);
        $this->authorizations->createRequest($this->authorizeParams($clientId));
    }

    /**
     * @param array<string, string> $override
     * @param string $error
     * @return void
     */
    #[DataProvider('redirectableErrorProvider')]
    public function testLaterErrorsGoBackToTheClient(array $override, string $error): void
    {
        $clientId = $this->registerClient()['client_id'];

        try {
            $this->authorizations->createRequest($this->authorizeParams($clientId, $override));
            $this->fail('The request was accepted.');
        } catch (RedirectableOAuthException $e) {
            $this->assertSame($error, $e->getError());
            $url = $e->getRedirectUrl();
            $this->assertStringStartsWith(self::CALLBACK . '?', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($error, $query['error']);
            $this->assertSame('xyz', $query['state']);
            $this->assertSame(self::BASE . '/magenx-mcp', $query['iss']);
        }

        $this->assertSame([], $this->storage->authorizations);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function redirectableErrorProvider(): array
    {
        return [
            'no PKCE' => [['code_challenge' => ''], 'invalid_request'],
            'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'malformed challenge' => [['code_challenge' => 'short'], 'invalid_request'],
            'implicit flow' => [['response_type' => 'token'], 'unsupported_response_type'],
            'unknown scope' => [['scope' => 'mcp:read admin'], 'invalid_scope'],
            'another resource' => [['resource' => 'https://other.example/mcp'], 'invalid_target'],
        ];
    }

    /**
     * @return void
     */
    public function testApprovalIssuesASingleUseCodeToTheCallback(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));

        $url = $this->authorizations->approve($requestId, 7, 'admin:alice', true);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(self::CALLBACK . '?', $url);
        $this->assertStringStartsWith('mxc_', $query['code']);
        $this->assertSame('xyz', $query['state']);
        $this->assertSame(self::BASE . '/magenx-mcp', $query['iss']);

        $row = $this->storage->authorizations[$requestId];
        $this->assertSame(Storage::STATUS_APPROVED, $row['status']);
        $this->assertSame(7, $row['admin_user_id']);
        $this->assertSame('mcp:read mcp:write', $row['granted_scope']);
        $this->assertSame(hash('sha256', $query['code']), $row['code_hash']);
        $this->assertNull($this->authorizations->getPending($requestId), 'An approved request must not be pending.');
    }

    /**
     * Write access is the admin's opt-in, not the client's.
     *
     * @return void
     */
    public function testApprovalWithoutTheWriteBoxIsReadOnly(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));

        $this->authorizations->approve($requestId, 7, 'admin:alice', false);

        $this->assertSame('mcp:read', $this->storage->authorizations[$requestId]['granted_scope']);
    }

    /**
     * Ticking write cannot widen a read-only request.
     *
     * @return void
     */
    public function testWriteCannotBeGrantedWhenNotRequested(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId, ['scope' => 'mcp:read']));

        $this->authorizations->approve($requestId, 7, 'admin:alice', true);

        $this->assertSame('mcp:read', $this->storage->authorizations[$requestId]['granted_scope']);
    }

    /**
     * @return void
     */
    public function testARequestCanOnlyBeDecidedOnce(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));
        $this->authorizations->approve($requestId, 7, 'admin:alice', true);

        $this->assertNull($this->authorizations->deny($requestId, 'admin:alice'));
        $this->expectException(OAuthException::class);
        $this->authorizations->approve($requestId, 7, 'admin:alice', true);
    }

    /**
     * @return void
     */
    public function testExpiredRequestCannotBeApproved(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));
        $this->now += AuthorizationService::REQUEST_LIFETIME + 1;

        $this->assertNull($this->authorizations->getPending($requestId));
        $this->expectException(OAuthException::class);
        $this->authorizations->approve($requestId, 7, 'admin:alice', true);
    }

    /**
     * @return void
     */
    public function testDenialRedirectsWithAccessDenied(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));

        $url = (string) $this->authorizations->deny($requestId, 'admin:alice');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('xyz', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(Storage::STATUS_DENIED, $this->storage->authorizations[$requestId]['status']);
    }

    /**
     * @return void
     */
    public function testMalformedRequestIdIsNeverLookedUp(): void
    {
        $this->assertNull($this->authorizations->getPending("' OR 1=1 --"));
    }

    /**
     * With registration closed, a client anyone registered learns nothing: no row, no redirect to the admin.
     *
     * @return void
     */
    public function testClosedRegistrationRefusesADynamicClientWithoutRedirecting(): void
    {
        $clientId = $this->registerClient()['client_id'];
        $this->dynamicRegistration = false;

        try {
            $this->authorizations->createRequest($this->authorizeParams($clientId));
            $this->fail('A dynamically registered client started a flow with registration closed.');
        } catch (OAuthException $e) {
            $this->assertNotInstanceOf(RedirectableOAuthException::class, $e);
            $this->assertSame('invalid_client', $e->getError());
            $this->assertStringContainsString('administrator created', $e->getMessage());
        }
        $this->assertSame([], $this->storage->authorizations);
    }

    /**
     * @return void
     */
    public function testClosedRegistrationAcceptsAnAdminClient(): void
    {
        $this->dynamicRegistration = false;
        $clientId = $this->clients->createForAdmin('Claude', 'admin:alice')['client_id'];

        $requestId = $this->authorizations->createRequest($this->authorizeParams($clientId));

        $this->assertSame(Storage::STATUS_PENDING, $this->storage->authorizations[$requestId]['status']);
    }
}
