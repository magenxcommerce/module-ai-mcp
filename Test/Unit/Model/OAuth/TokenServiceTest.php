<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\TokenService;

/**
 * Code exchange, refresh rotation, replay detection and access-token resolution.
 *
 * @see TokenService
 */
class TokenServiceTest extends OAuthTestCase
{
    /**
     * @return void
     */
    public function testCodeExchangesForATokenPair(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();

        $response = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->assertStringStartsWith('mxa_', $response['access_token']);
        $this->assertStringStartsWith('mxr_', $response['refresh_token']);
        $this->assertSame('Bearer', $response['token_type']);
        $this->assertSame(3600, $response['expires_in']);
        $this->assertSame('mcp:read mcp:write', $response['scope']);

        $row = array_values($this->storage->tokens)[0];
        $this->assertSame(hash('sha256', $response['access_token']), $row['access_hash']);
        $this->assertSame(self::BASE . '/magenx-mcp/server', $row['resource']);
        $this->assertSame($this->now + 30 * 86400, $row['refresh_expires_at']);
        $this->assertStringNotContainsString($response['access_token'], (string) json_encode($this->storage->tokens));
    }

    /**
     * @return void
     */
    public function testWrongVerifierIsRefused(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();

        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => strrev(self::VERIFIER)])
        );
    }

    /**
     * @return void
     */
    public function testCodeIsBoundToItsClient(): void
    {
        ['code' => $code] = $this->approvedCode();
        $other = $this->clients->find($this->registerClient()['client_id']);

        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->exchangeCode($other, ['code' => $code, 'code_verifier' => self::VERIFIER])
        );
    }

    /**
     * @return void
     */
    public function testExpiredCodeIsRefused(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $this->now += AuthorizationService::CODE_LIFETIME + 1;

        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER])
        );
    }

    /**
     * @return void
     */
    public function testRedirectUriMustMatchWhenSent(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();

        $this->assertOAuthError('invalid_grant', fn () => $this->tokens->exchangeCode(
            $client,
            ['code' => $code, 'code_verifier' => self::VERIFIER, 'redirect_uri' => 'https://claude.ai/other']
        ));
    }

    /**
     * @return void
     */
    public function testForeignResourceIsRefused(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();

        $this->assertOAuthError('invalid_target', fn () => $this->tokens->exchangeCode(
            $client,
            ['code' => $code, 'code_verifier' => self::VERIFIER, 'resource' => 'https://other.example/mcp']
        ));
    }

    /**
     * A replayed code means it leaked: everything issued from it is revoked.
     *
     * @return void
     */
    public function testReplayedCodeRevokesWhatItIssued(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        try {
            $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
            $this->fail('A code was redeemed twice.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->getError());
        }

        $this->assertNull($this->tokens->resolve($first['access_token']));
    }

    /**
     * @return void
     */
    public function testRefreshRotatesAndKeepsTheGrantsAbsoluteExpiry(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $grantExpiry = array_values($this->storage->tokens)[0]['refresh_expires_at'];

        $this->now += 86400;
        $second = $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']]);

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $rows = array_values($this->storage->tokens);
        $this->assertSame(1, $rows[0]['rotated']);
        $this->assertSame($rows[0]['family_id'], $rows[1]['family_id']);
        $this->assertSame($grantExpiry, $rows[1]['refresh_expires_at'], 'Refreshing must not extend the grant.');
        $this->assertNotNull($this->tokens->resolve($second['access_token']));
    }

    /**
     * A spent refresh token presented again means two parties hold it: the whole grant dies.
     *
     * @return void
     */
    public function testReplayedRefreshTokenRevokesTheGrant(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $second = $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']]);

        try {
            $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']]);
            $this->fail('A spent refresh token was accepted.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->getError());
        }

        $this->assertNull($this->tokens->resolve($second['access_token']));
        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->refresh($client, ['refresh_token' => $second['refresh_token']])
        );
    }

    /**
     * @return void
     */
    public function testGrantEndsAtItsAbsoluteExpiry(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->now += 30 * 86400 + 1;

        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']])
        );
    }

    /**
     * @return void
     */
    public function testAccessTokenNeverOutlivesTheGrant(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->now += 30 * 86400 - 600;

        $second = $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']]);

        $this->assertSame(600, $second['expires_in']);
    }

    /**
     * @return void
     */
    public function testRefreshCannotWidenScope(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode(grantWrite: false);
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->assertOAuthError(
            'invalid_scope',
            fn () => $this->tokens->refresh(
                $client,
                ['refresh_token' => $first['refresh_token'], 'scope' => 'mcp:write']
            )
        );
    }

    /**
     * @return void
     */
    public function testRefreshTokenIsBoundToItsClient(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $other = $this->clients->find($this->registerClient()['client_id']);

        $this->assertOAuthError(
            'invalid_grant',
            fn () => $this->tokens->refresh($other, ['refresh_token' => $first['refresh_token']])
        );
    }

    /**
     * @return void
     */
    public function testResolveCarriesTheAdminAndTheWriteScope(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode(grantWrite: false);
        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        $grant = $this->tokens->resolve($pair['access_token']);

        $this->assertNotNull($grant);
        $this->assertSame(7, $grant->getAdminUserId());
        $this->assertSame('alice', $grant->getUsername());
        $this->assertSame('Claude', $grant->getClientName());
        $this->assertFalse($grant->allowsWrites());
    }

    /**
     * @return void
     */
    public function testExpiredAccessTokenDoesNotResolve(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->now += 3601;

        $this->assertNull($this->tokens->resolve($pair['access_token']));
    }

    /**
     * @return void
     */
    public function testDeactivatedOrLockedAdminActsThroughNoConnection(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        $this->storage->admins[7]['is_active'] = 0;
        $this->assertNull($this->tokens->resolve($pair['access_token']));

        $this->storage->admins[7]['is_active'] = 1;
        $this->storage->admins[7]['lock_expires'] = gmdate('Y-m-d H:i:s', $this->now + 600);
        $this->assertNull($this->tokens->resolve($pair['access_token']));

        $this->storage->admins[7]['lock_expires'] = gmdate('Y-m-d H:i:s', $this->now - 600);
        $this->assertNotNull($this->tokens->resolve($pair['access_token']));
    }

    /**
     * A token issued for this server stops working if the server's public URL changes.
     *
     * @return void
     */
    public function testTokenForAnotherAudienceDoesNotResolve(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $id = array_key_first($this->storage->tokens);
        $this->storage->tokens[$id]['resource'] = 'https://old-host.example/magenx-mcp/server';

        $this->assertNull($this->tokens->resolve($pair['access_token']));
    }

    /**
     * @return void
     */
    public function testRevocationEndsTheWholeGrant(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $first = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $second = $this->tokens->refresh($client, ['refresh_token' => $first['refresh_token']]);

        $this->tokens->revoke($client, $second['refresh_token']);

        $this->assertNull($this->tokens->resolve($second['access_token']));
        $this->assertNull($this->tokens->resolve($first['access_token']));
    }

    /**
     * Another client cannot revoke a grant it does not hold.
     *
     * @return void
     */
    public function testRevocationByAnotherClientIsIgnored(): void
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode();
        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);
        $other = $this->clients->find($this->registerClient()['client_id']);

        $this->tokens->revoke($other, $pair['access_token']);

        $this->assertNotNull($this->tokens->resolve($pair['access_token']));
    }

    /**
     * @return void
     */
    public function testNonOAuthTokensAreNotLookedUp(): void
    {
        $this->assertNull($this->tokens->resolve('integration-token-value'));
    }

    /**
     * Run a call that must fail with this OAuth error code.
     *
     * @param string $error
     * @param callable $call
     * @return void
     */
    private function assertOAuthError(string $error, callable $call): void
    {
        try {
            $call();
        } catch (OAuthException $e) {
            $this->assertSame($error, $e->getError());

            return;
        }

        $this->fail(sprintf('Expected OAuth error "%s"; the call succeeded.', $error));
    }
}
