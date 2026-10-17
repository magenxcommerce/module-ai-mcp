<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\Storage;

/**
 * Admin-created and open registration, gated by the redirect allowlist; client authentication at the token endpoint.
 *
 * @see ClientRegistry
 */
class ClientRegistryTest extends OAuthTestCase
{
    /**
     * @return void
     */
    public function testPublicClientRegistersWithoutASecret(): void
    {
        $response = $this->registerClient();

        $this->assertStringStartsWith('mxclient_', $response['client_id']);
        $this->assertArrayNotHasKey('client_secret', $response);
        $this->assertSame([self::CALLBACK], $response['redirect_uris']);
        $this->assertNull($this->storage->clients[$response['client_id']]['secret_hash']);
    }

    /**
     * @return void
     */
    public function testConfidentialClientGetsASecretThatIsStoredOnlyAsAHash(): void
    {
        $response = $this->registerClient(ClientRegistry::AUTH_POST);

        $this->assertStringStartsWith('mxs_', $response['client_secret']);
        $stored = $this->storage->clients[$response['client_id']]['secret_hash'];
        $this->assertSame(hash('sha256', $response['client_secret']), $stored);
        $this->assertStringNotContainsString($response['client_secret'], (string) json_encode($this->storage->clients));
    }

    /**
     * An unlisted callback is the attack open registration would otherwise allow.
     *
     * @return void
     */
    public function testUnlistedRedirectIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->clients->register(['redirect_uris' => [self::CALLBACK, 'https://evil.example/cb']], '203.0.113.7');
            $this->fail('An unlisted redirect URI was registered.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_redirect_uri', $e->getError());
        }

        $this->assertSame([], $this->storage->clients);
    }

    /**
     * @return void
     */
    public function testMissingRedirectUrisIsRefused(): void
    {
        $this->expectException(OAuthException::class);
        $this->clients->register(['client_name' => 'x'], '203.0.113.7');
    }

    /**
     * @return void
     */
    public function testUnsupportedGrantTypeIsRefused(): void
    {
        $this->expectException(OAuthException::class);
        $this->clients->register(
            ['redirect_uris' => [self::CALLBACK], 'grant_types' => ['client_credentials']],
            '203.0.113.7'
        );
    }

    /**
     * @return void
     */
    public function testClientNameIsCleanedAndDefaulted(): void
    {
        $named = $this->clients->register(
            ['client_name' => "Claude\n<b>x</b>", 'redirect_uris' => [self::CALLBACK]],
            '203.0.113.7'
        );
        $unnamed = $this->clients->register(['redirect_uris' => [self::CALLBACK]], '203.0.113.7');

        $this->assertSame('Claude <b>x</b>', $named['client_name']);
        $this->assertSame('claude.ai', $unnamed['client_name']);
    }

    /**
     * RFC 7591 §2: a client that names no method is a client_secret_basic client.
     *
     * @return void
     */
    public function testOmittedAuthMethodMeansClientSecretBasic(): void
    {
        $response = $this->clients->register(['redirect_uris' => [self::CALLBACK]], '203.0.113.7');

        $this->assertSame(ClientRegistry::AUTH_BASIC, $response['token_endpoint_auth_method']);
        $this->assertArrayHasKey('client_secret', $response);
        $this->assertSame(0, $response['client_secret_expires_at']);
    }

    /**
     * @return void
     */
    public function testPublicClientAuthenticatesWithItsIdAlone(): void
    {
        $id = $this->registerClient()['client_id'];

        $this->assertSame($id, $this->clients->authenticate($id, null, ClientRegistry::AUTH_NONE)['client_id']);
    }

    /**
     * @return void
     */
    public function testConfidentialClientCannotDropItsSecret(): void
    {
        $id = $this->registerClient(ClientRegistry::AUTH_POST)['client_id'];

        $this->expectException(OAuthException::class);
        $this->clients->authenticate($id, null, ClientRegistry::AUTH_NONE);
    }

    /**
     * @return void
     */
    public function testWrongSecretIsRefusedWith401(): void
    {
        $id = $this->registerClient(ClientRegistry::AUTH_BASIC)['client_id'];

        try {
            $this->clients->authenticate($id, 'mxs_wrong', ClientRegistry::AUTH_BASIC);
            $this->fail('A wrong secret authenticated.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->getError());
            $this->assertSame(401, $e->getHttpStatus());
        }
    }

    /**
     * @return void
     */
    public function testRightSecretWithTheRegisteredMethodAuthenticates(): void
    {
        $registration = $this->registerClient(ClientRegistry::AUTH_BASIC);

        $client = $this->clients->authenticate(
            $registration['client_id'],
            $registration['client_secret'],
            ClientRegistry::AUTH_BASIC
        );

        $this->assertSame($registration['client_id'], $client['client_id']);
    }

    /**
     * @return void
     */
    public function testUnknownClientIsRefused(): void
    {
        $this->expectException(OAuthException::class);
        $this->clients->authenticate('mxclient_nope', null, ClientRegistry::AUTH_NONE);
    }

    /**
     * @return void
     */
    public function testAdminClientIsConfidentialAndServesEveryListedCallback(): void
    {
        $this->allowedRedirects = [self::CALLBACK, 'https://claude.com/api/mcp/auth_callback'];

        $created = $this->clients->createForAdmin('', 'admin:alice');

        $this->assertStringStartsWith('mxclient_', $created['client_id']);
        $this->assertStringStartsWith('mxs_', $created['client_secret']);
        $stored = $this->storage->clients[$created['client_id']];
        $this->assertSame(Storage::ORIGIN_ADMIN, $stored['origin']);
        $this->assertSame('Claude', $stored['client_name']);
        $this->assertSame(hash('sha256', $created['client_secret']), $stored['secret_hash']);
        $this->assertSame($this->allowedRedirects, $this->clients->getRedirectUris($stored));
    }

    /**
     * @return void
     */
    public function testAdminClientNeedsACallbackOnTheAllowlist(): void
    {
        $this->allowedRedirects = [];

        try {
            $this->clients->createForAdmin('Claude', 'admin:alice');
            $this->fail('A client was created with nowhere to send its codes.');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_redirect_uri', $e->getError());
        }
        $this->assertSame([], $this->storage->clients);
    }

    /**
     * The client never told us which method it uses, so either carries the secret.
     *
     * @return void
     */
    public function testAdminClientPresentsItsSecretEitherWay(): void
    {
        $created = $this->clients->createForAdmin('Claude', 'admin:alice');

        foreach ([ClientRegistry::AUTH_POST, ClientRegistry::AUTH_BASIC] as $method) {
            $client = $this->clients->authenticate($created['client_id'], $created['client_secret'], $method);
            $this->assertSame($created['client_id'], $client['client_id']);
        }
    }

    /**
     * What defeats consent phishing: an approval the attacker talked an admin into is useless without the secret.
     *
     * @return void
     */
    public function testAdminClientCannotAuthenticateWithoutItsSecret(): void
    {
        $created = $this->clients->createForAdmin('Claude', 'admin:alice');

        foreach ([[null, ClientRegistry::AUTH_NONE], ['mxs_wrong', ClientRegistry::AUTH_POST]] as [$secret, $method]) {
            try {
                $this->clients->authenticate($created['client_id'], $secret, $method);
                $this->fail('An admin-created client authenticated without its secret.');
            } catch (OAuthException $e) {
                $this->assertSame('invalid_client', $e->getError());
            }
        }
    }

    /**
     * @return void
     */
    public function testOnlyAdminClientsMayStartAFlowOnceRegistrationIsClosed(): void
    {
        $dynamic = $this->clients->find($this->registerClient()['client_id']);
        $admin = $this->clients->find($this->clients->createForAdmin('Claude', 'admin:alice')['client_id']);
        self::assertIsArray($dynamic);
        self::assertIsArray($admin);

        $this->assertTrue($this->clients->mayStartFlow($dynamic));
        $this->dynamicRegistration = false;
        $this->assertFalse($this->clients->mayStartFlow($dynamic));
        $this->assertTrue($this->clients->mayStartFlow($admin));
    }
}
