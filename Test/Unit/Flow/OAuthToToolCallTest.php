<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Flow;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Audit\AdminActivityRecorder;
use Magenx\AiMcp\Model\Auth\AclResources;
use Magenx\AiMcp\Model\Auth\Authenticator;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magenx\AiMcp\Test\Unit\Model\OAuth\OAuthTestCase;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Helper\Oauth\Data as OauthHelper;
use Magento\Integration\Model\Oauth\TokenFactory;
use Psr\Log\LoggerInterface;

/**
 * One connection end to end: approval, code exchange, the bearer token on the
 * MCP endpoint, the admin's role, and what the server then lets it do.
 *
 * The real services run over in-memory storage; only Magento's ACL data and
 * HTTP plumbing are stood in for. This is the path the admin-role bug broke -
 * it is pinned here so the pieces cannot drift apart again unnoticed.
 */
class OAuthToToolCallTest extends OAuthTestCase
{
    private const RESOURCE = 'Magento_Catalog::products';

    /** @var string[] What alice's group role grants. */
    private array $roleResources = [Server::ENDPOINT_RESOURCE, self::RESOURCE];

    /** @var array<int, array{string, string}> Tool runs seen by the activity recorder: [caller, tool]. */
    private array $recorded = [];

    /**
     * @return void
     */
    public function testAReadOnlyConnectionCanReadButNotWrite(): void
    {
        $identity = $this->authenticate($this->accessToken(grantWrite: false));

        $this->assertSame('admin:alice via Claude', $identity->getLabel());
        $this->assertSame(['list_things'], $this->toolNames($identity));

        $result = $this->call($identity, 'do_thing');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('approved for reading only', $result['content'][0]['text']);
        $this->assertSame([], $this->recorded);
    }

    /**
     * @return void
     */
    public function testAWriteConnectionWritesAndTheChangeIsRecordedAsTheAdmin(): void
    {
        $identity = $this->authenticate($this->accessToken(grantWrite: true));

        $this->assertSame(['do_thing', 'list_things'], $this->toolNames($identity));

        $result = $this->call($identity, 'do_thing');
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertSame([['admin:alice via Claude', 'do_thing']], $this->recorded);
    }

    /**
     * Every role, Administrators included, keeps its rules on the group role.
     *
     * @return void
     */
    public function testAnAdministratorRoleReachesEverything(): void
    {
        $this->roleResources = ['Magento_Backend::all'];

        $identity = $this->authenticate($this->accessToken(grantWrite: true));

        $this->assertSame(['do_thing', 'list_things'], $this->toolNames($identity));
    }

    /**
     * @return void
     */
    public function testARoleWithoutTheEndpointGrantIsRefusedTheHandshake(): void
    {
        $this->roleResources = [self::RESOURCE];
        $identity = $this->authenticate($this->accessToken(grantWrite: true));

        $outcome = $this->server()->dispatch('initialize', [], 1, $identity);

        $this->assertSame(403, $outcome->getHttpStatus());
    }

    /**
     * @return void
     */
    public function testARevokedConnectionIsRefused(): void
    {
        $token = $this->accessToken(grantWrite: true);
        $this->storage->revokeAdmin(7);

        $this->expectException(AuthenticationException::class);
        $this->authenticate($token);
    }

    /**
     * Deactivating an integration cuts its token off here as it does on Magento's REST API.
     *
     * @return void
     */
    public function testAnInactiveIntegrationsTokenIsRefused(): void
    {
        $integration = new class {
            /** @return int */
            public function getId(): int
            {
                return 3;
            }

            /** @return string */
            public function getName(): string
            {
                return 'Reporting';
            }

            /** @return int */
            public function getStatus(): int
            {
                return 0;
            }
        };
        $integrations = $this->createMock(IntegrationServiceInterface::class);
        $integrations->method('findByConsumerId')->willReturn($integration);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('not active');
        $this->authenticator($integrations, $this->integrationTokenFactory())
            ->authenticate(new HttpRequest(['Authorization' => 'Bearer integration-token']));
    }

    /**
     * Register, authorize, approve and exchange; return the access token.
     *
     * @param bool $grantWrite
     * @return string
     */
    private function accessToken(bool $grantWrite): string
    {
        ['client' => $client, 'code' => $code] = $this->approvedCode($grantWrite);

        $pair = $this->tokens->exchangeCode($client, ['code' => $code, 'code_verifier' => self::VERIFIER]);

        return $pair['access_token'];
    }

    /**
     * @param string $token
     * @return Identity
     */
    private function authenticate(string $token): Identity
    {
        return $this->authenticator()->authenticate(new HttpRequest(['Authorization' => 'Bearer ' . $token]));
    }

    /**
     * @param IntegrationServiceInterface|null $integrations
     * @param TokenFactory|null $tokenFactory
     * @return Authenticator
     */
    private function authenticator(
        ?IntegrationServiceInterface $integrations = null,
        ?TokenFactory $tokenFactory = null
    ): Authenticator {
        GeneratedFactory::ensure(TokenFactory::class);
        GeneratedFactory::ensure(RoleCollectionFactory::class);

        $retriever = $this->createMock(AclRetriever::class);
        $retriever->method('getAllowedResourcesByRole')->willReturnCallback(
            fn ($roleId): array => (int) $roleId === 1 ? $this->roleResources : []
        );
        $retriever->method('getAllowedResourcesByUser')->willReturn([Server::ENDPOINT_RESOURCE]);
        $collection = $this->createMock(RoleCollection::class);
        $collection->method('setUserFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(new DataObject(['role_id' => 2, 'parent_id' => 1]));
        $roles = $this->createMock(RoleCollectionFactory::class);
        $roles->method('create')->willReturn($collection);

        $date = $this->createMock(CoreDate::class);
        $date->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new Authenticator(
            $tokenFactory ?? $this->createMock(TokenFactory::class),
            $integrations ?? $this->createMock(IntegrationServiceInterface::class),
            $this->createMock(OauthHelper::class),
            $date,
            $this->createMock(RemoteAddress::class),
            $this->createMock(Config::class),
            $this->createMock(LoggerInterface::class),
            $this->oauthConfig,
            $this->tokens,
            new AclResources($retriever, $roles)
        );
    }

    /**
     * A token factory whose token is a live integration token.
     *
     * @return TokenFactory
     */
    private function integrationTokenFactory(): TokenFactory
    {
        GeneratedFactory::ensure(TokenFactory::class);
        $token = new class {
            /** @return self */
            public function loadByToken(): self
            {
                return $this;
            }

            /** @return int */
            public function getId(): int
            {
                return 5;
            }

            /** @return int */
            public function getRevoked(): int
            {
                return 0;
            }

            /** @return int */
            public function getUserType(): int
            {
                return 1;
            }

            /** @return int */
            public function getConsumerId(): int
            {
                return 9;
            }
        };
        $factory = $this->createMock(TokenFactory::class);
        $factory->method('create')->willReturn($token);

        return $factory;
    }

    /**
     * @param Identity $identity
     * @return string[]
     */
    private function toolNames(Identity $identity): array
    {
        $tools = $this->server()->dispatch('tools/list', [], 1, $identity)->getBody()['result']['tools'];
        $names = array_column($tools, 'name');
        sort($names);

        return $names;
    }

    /**
     * @param Identity $identity
     * @param string $tool
     * @return array<string, mixed>
     */
    private function call(Identity $identity, string $tool): array
    {
        return $this->server()->dispatch(
            'tools/call',
            ['name' => $tool, 'arguments' => ['confirm' => true]],
            1,
            $identity
        )->getBody()['result'];
    }

    /**
     * @return Server
     */
    private function server(): Server
    {
        $config = $this->createMock(Config::class);
        $config->method('isWriteAllowed')->willReturn(true);
        $recorder = $this->createMock(AdminActivityRecorder::class);
        $recorder->method('run')->willReturnCallback(
            function (Identity $identity, string $tool, callable $work): array {
                $this->recorded[] = [$identity->getLabel(), $tool];

                return $work();
            }
        );
        $registry = new ToolRegistry([$this->tool('list_things', false), $this->tool('do_thing', true)]);

        return new Server(
            $registry,
            new ToolCatalog($registry),
            $config,
            new JsonRpc(),
            new Json(),
            $this->createMock(LoggerInterface::class),
            '1.0.0',
            null,
            $recorder
        );
    }

    /**
     * @param string $name
     * @param bool $isWrite
     * @return ToolInterface
     */
    private function tool(string $name, bool $isWrite): ToolInterface
    {
        return new class ($name, $isWrite) implements ToolInterface {
            /**
             * @param string $name
             * @param bool $isWrite
             */
            public function __construct(private readonly string $name, private readonly bool $isWrite)
            {
            }

            /** @inheritDoc */
            public function getName(): string
            {
                return $this->name;
            }

            /** @inheritDoc */
            public function getDescription(): string
            {
                return 'Test tool.';
            }

            /** @inheritDoc */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            /** @inheritDoc */
            public function getAclResource(): string
            {
                return 'Magento_Catalog::products';
            }

            /** @inheritDoc */
            public function isWrite(): bool
            {
                return $this->isWrite;
            }

            /** @inheritDoc */
            public function execute(array $arguments): array
            {
                return ['done' => true];
            }
        };
    }
}
