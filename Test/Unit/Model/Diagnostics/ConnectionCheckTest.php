<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Diagnostics;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Auth\AclResources;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Diagnostics\ConnectionCheck;
use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What `bin/magento magenx:mcp:check` reports for a caller.
 *
 * @see ConnectionCheck
 */
class ConnectionCheckTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        GeneratedFactory::ensure(UserFactory::class);
    }

    /**
     * @return void
     */
    public function testAnAdminWithTheGrantsIsUsable(): void
    {
        $report = $this->check([Server::ENDPOINT_RESOURCE, 'Magento_Catalog::products'])->forAdmin('alice');

        $this->assertTrue($report['ok'], implode(' ', $report['problems']));
        $this->assertSame('yes', $report['facts']['endpoint grant']);
        $this->assertSame('2', $report['facts']['tools offered']);
        $this->assertSame('1', $report['facts']['write tools offered']);
    }

    /**
     * The failure the admin-role bug produced: reachable endpoint, refused handshake.
     *
     * @return void
     */
    public function testAnAdminWithoutTheEndpointGrantIsReported(): void
    {
        $report = $this->check(['Magento_Catalog::products'])->forAdmin('alice');

        $this->assertFalse($report['ok']);
        $this->assertSame('refused', $report['facts']['initialize']);
        $this->assertStringContainsString('AI MCP Server', implode(' ', $report['problems']));
    }

    /**
     * @return void
     */
    public function testAGrantWithNoToolResourcesIsReported(): void
    {
        $report = $this->check([Server::ENDPOINT_RESOURCE])->forAdmin('alice');

        $this->assertFalse($report['ok']);
        $this->assertSame('0', $report['facts']['tools offered']);
    }

    /**
     * @return void
     */
    public function testAnUnknownAdminIsReported(): void
    {
        $report = $this->check([], userId: null)->forAdmin('nobody');

        $this->assertFalse($report['ok']);
        $this->assertSame([], $report['facts']);
    }

    /**
     * @return void
     */
    public function testAnInactiveIntegrationIsReported(): void
    {
        $integration = new class {
            /** @return int */
            public function getId(): int
            {
                return 3;
            }

            /** @return int */
            public function getStatus(): int
            {
                return 0;
            }
        };

        $report = $this->check([Server::ENDPOINT_RESOURCE, 'Magento_Catalog::products'], integration: $integration)
            ->forIntegration('Reporting');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('not active', implode(' ', $report['problems']));
        $this->assertSame('2', $report['facts']['tools offered']);
    }

    /**
     * @param string[] $resources
     * @param int|null $userId
     * @param object|null $integration
     * @return ConnectionCheck
     */
    private function check(array $resources, ?int $userId = 7, ?object $integration = null): ConnectionCheck
    {
        $acl = $this->createMock(AclResources::class);
        $acl->method('forUser')->willReturn($resources);

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isWriteAllowed')->willReturn(true);

        $user = $this->createMock(User::class);
        $user->method('loadByUsername')->willReturnSelf();
        $user->method('getId')->willReturn($userId);
        $user->method('getIsActive')->willReturn(1);
        $users = $this->createMock(UserFactory::class);
        $users->method('create')->willReturn($user);

        $integrations = $this->createMock(IntegrationServiceInterface::class);
        if ($integration !== null) {
            $integrations->method('findByName')->willReturn($integration);
        }

        $registry = new ToolRegistry([$this->tool('list_things', false), $this->tool('do_thing', true)]);
        $server = new Server(
            $registry,
            new ToolCatalog($registry),
            $config,
            new JsonRpc(),
            new Json(),
            $this->createMock(LoggerInterface::class),
            '1.0.0'
        );

        return new ConnectionCheck(
            $acl,
            $server,
            $config,
            $this->createMock(OAuthConfig::class),
            $this->createMock(Endpoints::class),
            $users,
            $integrations
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
                return [];
            }
        };
    }
}
