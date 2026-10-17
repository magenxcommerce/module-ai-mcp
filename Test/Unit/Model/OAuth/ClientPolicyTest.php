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
 * A client's policy, end to end: approvers, tool-area cap, disabling, secret rotation and re-approval.
 *
 * @see ClientRegistry::updatePolicy()
 */
class ClientPolicyTest extends OAuthTestCase
{
    private const ALICE = 7;
    private const BOB = 8;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->storage->admins[self::BOB] = ['username' => 'bob', 'is_active' => 1, 'lock_expires' => null];
    }

    /**
     * @return void
     */
    public function testAnyAdminMayApproveAClientWithoutApprovers(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');

        $this->assertArrayHasKey('access_token', $this->connect($client, self::BOB));
    }

    /**
     * The point of the list: a phished admin who is not on it cannot hand the client their role.
     *
     * @return void
     */
    public function testOnlyListedApproversMayApprove(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [self::ALICE]);

        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));
        $pending = $this->authorizations->getPending($requestId);
        $this->assertNotNull($this->authorizations->getRefusal($pending, self::BOB));
        $this->assertOAuthError(
            'access_denied',
            fn () => $this->authorizations->approve($requestId, self::BOB, 'admin:bob', false)
        );
        // Refused, not spent: a listed approver can still answer it.
        $this->assertNull($this->authorizations->getRefusal($pending, self::ALICE));
        $this->assertStringContainsString(
            'code=',
            $this->authorizations->approve($requestId, self::ALICE, 'admin:alice', false)
        );
    }

    /**
     * @return void
     */
    public function testNarrowingApproversEndsTheConnectionsOfThoseTakenOff(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $alice = $this->connect($client, self::ALICE);
        $bob = $this->connect($client, self::BOB);

        $this->setPolicy($client['client_id'], [self::ALICE]);

        $this->assertNotNull($this->tokens->resolve($alice['access_token']));
        $this->assertNull($this->tokens->resolve($bob['access_token']));
    }

    /**
     * @return void
     */
    public function testAnApproverMustBeAnActiveAdmin(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->storage->admins[self::BOB]['is_active'] = 0;

        $this->assertOAuthError('invalid_request', fn () => $this->setPolicy($client['client_id'], [self::BOB]));
        $this->assertSame([], $this->storage->getClientApprovers($client['client_id']));
    }

    /**
     * @return void
     */
    public function testPolicyIsOnlyForAdminCreatedClients(): void
    {
        $dynamic = $this->registerClient();

        $this->assertOAuthError('invalid_client', fn () => $this->setPolicy($dynamic['client_id'], []));
        $this->assertFalse($this->clients->setDisabled($dynamic['client_id'], true, 'admin:alice'));
        $this->assertNull($this->clients->rotateSecret($dynamic['client_id'], 'admin:alice'));
    }

    /**
     * @return void
     */
    public function testTickedToolAreasAreWhatTheConnectionCarries(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');

        $tokens = $this->connect($client, self::ALICE, ['sales', 'catalog']);

        $this->assertSame(['catalog', 'sales'], $this->tokens->resolve($tokens['access_token'])->getDomains());

        // A refresh keeps them.
        $refreshed = $this->tokens->refresh(
            $this->authenticated($client),
            ['refresh_token' => $tokens['refresh_token']]
        );
        $this->assertSame(['catalog', 'sales'], $this->tokens->resolve($refreshed['access_token'])->getDomains());
    }

    /**
     * @return void
     */
    public function testEveryAreaTickedOnAnUncappedClientMeansNoLimit(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');

        $tokens = $this->connect($client, self::ALICE);

        $this->assertNull($this->tokens->resolve($tokens['access_token'])->getDomains());
    }

    /**
     * @return void
     */
    public function testTheClientsCapBoundsWhatIsTickedAndNarrowsLiveConnections(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], ['catalog', 'sales']);

        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));
        $this->assertSame(
            ['catalog', 'sales'],
            $this->authorizations->getOfferedDomains($this->authorizations->getPending($requestId))
        );

        // Ticking something outside the cap gets only what is inside it.
        $tokens = $this->connect($client, self::ALICE, ['sales', 'customer']);
        $this->assertSame(['sales'], $this->tokens->resolve($tokens['access_token'])->getDomains());

        $uncapped = $this->connect($client, self::ALICE);
        $this->assertSame(['catalog', 'sales'], $this->tokens->resolve($uncapped['access_token'])->getDomains());

        // Narrowed later: the live connection narrows with it, nobody signs in again.
        $this->setPolicy($client['client_id'], [], ['catalog']);
        $this->assertSame(['catalog'], $this->tokens->resolve($uncapped['access_token'])->getDomains());
    }

    /**
     * @return void
     */
    public function testApprovingWithNoAreaTickedIsRefused(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], ['catalog']);
        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));

        $this->assertOAuthError(
            'invalid_request',
            fn () => $this->authorizations->approve($requestId, self::ALICE, 'admin:alice', false, ['sales'])
        );
        $this->assertNotNull($this->authorizations->getPending($requestId));
    }

    /**
     * Disabled, a client is refused everywhere with the error an unknown one gets, and keeps its connections.
     *
     * @return void
     */
    public function testDisabledClientReadsAsUnknownAndKeepsItsConnections(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $tokens = $this->connect($client, self::ALICE);
        $pending = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));

        $this->assertTrue($this->clients->setDisabled($client['client_id'], true, 'admin:alice'));

        $unknown = $this->errorOf(
            fn () => $this->authorizations->createRequest($this->authorizeParams('mxclient_nope'))
        );
        $disabled = $this->errorOf(
            fn () => $this->authorizations->createRequest($this->authorizeParams($client['client_id']))
        );
        $this->assertSame($unknown->toArray(), $disabled->toArray());

        $unknown = $this->errorOf(
            fn () => $this->clients->authenticate('mxclient_nope', 'mxs_x', ClientRegistry::AUTH_BASIC)
        );
        $disabled = $this->errorOf(fn () => $this->authenticated($client));
        $this->assertSame($unknown->toArray(), $disabled->toArray());
        $this->assertSame($unknown->getHttpStatus(), $disabled->getHttpStatus());

        $this->assertNull($this->tokens->resolve($tokens['access_token']));
        $this->assertNull($this->authorizations->getPending($pending));

        $this->assertTrue($this->clients->setDisabled($client['client_id'], false, 'admin:alice'));
        $this->assertNotNull($this->tokens->resolve($tokens['access_token']));
    }

    /**
     * @return void
     */
    public function testRotatedSecretReplacesTheOldOneAndKeepsConnections(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $tokens = $this->connect($client, self::ALICE);

        $newSecret = $this->clients->rotateSecret($client['client_id'], 'admin:alice');

        $this->assertNotSame($client['client_secret'], $newSecret);
        $this->assertOAuthError('invalid_client', fn () => $this->authenticated($client));
        $rotated = ['client_id' => $client['client_id'], 'client_secret' => (string) $newSecret];
        $refreshed = $this->tokens->refresh(
            $this->authenticated($rotated),
            ['refresh_token' => $tokens['refresh_token']]
        );
        $this->assertNotNull($this->tokens->resolve($refreshed['access_token']));
    }

    /**
     * @return void
     */
    public function testReapprovalAddsBesideTheFirstByDefault(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');

        $first = $this->connect($client, self::ALICE);
        $second = $this->connect($client, self::ALICE);

        $this->assertNotNull($this->tokens->resolve($first['access_token']));
        $this->assertNotNull($this->tokens->resolve($second['access_token']));
    }

    /**
     * @return void
     */
    public function testReapprovalCanReplaceTheEarlierConnection(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], null, Storage::REAPPROVAL_REPLACE);
        $first = $this->connect($client, self::ALICE);
        $bob = $this->connect($client, self::BOB);

        $second = $this->connect($client, self::ALICE);

        $this->assertNull($this->tokens->resolve($first['access_token']));
        $this->assertNotNull($this->tokens->resolve($second['access_token']));
        $this->assertNotNull($this->tokens->resolve($bob['access_token']), 'Another admin\'s connection is theirs.');
    }

    /**
     * Replaced only when the new code is redeemed, so an abandoned approval costs nothing.
     *
     * @return void
     */
    public function testReplaceKeepsTheEarlierConnectionUntilTheNewCodeIsRedeemed(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], null, Storage::REAPPROVAL_REPLACE);
        $first = $this->connect($client, self::ALICE);

        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));
        $this->authorizations->approve($requestId, self::ALICE, 'admin:alice', false);

        $this->assertNotNull($this->tokens->resolve($first['access_token']));
    }

    /**
     * @return void
     */
    public function testReapprovalCanBeRefusedWhileAConnectionIsLive(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], null, Storage::REAPPROVAL_REJECT);
        $first = $this->connect($client, self::ALICE);

        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));
        $this->assertOAuthError(
            'access_denied',
            fn () => $this->authorizations->approve($requestId, self::ALICE, 'admin:alice', false)
        );
        $this->assertNotNull($this->tokens->resolve($first['access_token']));
        // Another admin is not affected.
        $this->assertArrayHasKey('access_token', $this->connect($client, self::BOB));

        // Once the first is revoked, the admin may connect again.
        $this->storage->revokeAdmin(self::ALICE);
        $this->assertArrayHasKey('access_token', $this->connect($client, self::ALICE));
    }

    /**
     * Two approvals side by side both pass the consent check; only the first code redeemed may connect.
     *
     * @return void
     */
    public function testRejectHoldsForTwoApprovalsMadeSideBySide(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');
        $this->setPolicy($client['client_id'], [], null, Storage::REAPPROVAL_REJECT);
        $first = $this->approve($client, self::ALICE);
        $second = $this->approve($client, self::ALICE);

        $this->redeem($client, $first);

        $this->assertOAuthError('invalid_grant', fn () => $this->redeem($client, $second));
    }

    /**
     * @return void
     */
    public function testUnknownReapprovalModeIsRefused(): void
    {
        $client = $this->clients->createForAdmin('Claude', 'admin:alice');

        $this->assertOAuthError('invalid_request', fn () => $this->setPolicy($client['client_id'], [], null, 'merge'));
    }

    /**
     * @param string $clientId
     * @param int[] $approvers
     * @param string[]|null $domains
     * @param string $reapproval
     * @return void
     */
    private function setPolicy(
        string $clientId,
        array $approvers,
        ?array $domains = null,
        string $reapproval = Storage::REAPPROVAL_ADD
    ): void {
        $this->clients->updatePolicy($clientId, $approvers, $domains, $reapproval, 'admin:alice');
    }

    /**
     * Authorize, approve and redeem: the token response.
     *
     * @param array{client_id: string, client_secret: string} $client
     * @param int $adminUserId
     * @param string[]|null $domains
     * @return array<string, mixed>
     */
    private function connect(array $client, int $adminUserId, ?array $domains = null): array
    {
        return $this->redeem($client, $this->approve($client, $adminUserId, $domains));
    }

    /**
     * @param array{client_id: string, client_secret: string} $client
     * @param int $adminUserId
     * @param string[]|null $domains
     * @return string The code.
     */
    private function approve(array $client, int $adminUserId, ?array $domains = null): string
    {
        $requestId = $this->authorizations->createRequest($this->authorizeParams($client['client_id']));
        $redirect = $this->authorizations->approve($requestId, $adminUserId, 'admin#' . $adminUserId, true, $domains);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return (string) $query['code'];
    }

    /**
     * @param array{client_id: string, client_secret: string} $client
     * @param string $code
     * @return array<string, mixed>
     */
    private function redeem(array $client, string $code): array
    {
        return $this->tokens->exchangeCode(
            $this->authenticated($client),
            ['code' => $code, 'code_verifier' => self::VERIFIER]
        );
    }

    /**
     * The client as the token endpoint authenticates it.
     *
     * @param array{client_id: string, client_secret: string} $client
     * @return array<string, mixed>
     */
    private function authenticated(array $client): array
    {
        return $this->clients->authenticate($client['client_id'], $client['client_secret'], ClientRegistry::AUTH_BASIC);
    }

    /**
     * @param callable $call
     * @return OAuthException
     */
    private function errorOf(callable $call): OAuthException
    {
        try {
            $call();
        } catch (OAuthException $e) {
            return $e;
        }

        $this->fail('Expected an OAuth error; the call succeeded.');
    }

    /**
     * @param string $error
     * @param callable $call
     * @return void
     */
    private function assertOAuthError(string $error, callable $call): void
    {
        $this->assertSame($error, $this->errorOf($call)->getError());
    }
}
