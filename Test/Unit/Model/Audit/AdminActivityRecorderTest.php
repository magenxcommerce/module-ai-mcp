<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Audit;

use Magenx\AdminActivity\Model\Activity\ExternalActor;
use Magenx\AdminActivity\Model\Activity\ExternalActorScope;
use Magenx\AiMcp\Model\Audit\AdminActivityRecorder;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\UserContextInterface;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the admin activity log is told about the caller behind an MCP write.
 *
 * @see AdminActivityRecorder
 */
class AdminActivityRecorderTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        GeneratedFactory::ensure(UserFactory::class);
    }

    /**
     * An OAuth connection is recorded as the admin who approved it, with their role and the client.
     *
     * @return void
     */
    public function testOAuthAdminIsRecordedWithRoleAndClient(): void
    {
        $identity = new Identity(
            UserContextInterface::USER_TYPE_ADMIN,
            7,
            'admin:alice via Claude',
            [],
            true,
            'Claude'
        );

        $actor = $this->recorder($this->user('alice', 'Catalog Managers'))->actor($identity, 'update_product');

        $this->assertSame(7, $actor->getUserId());
        $this->assertSame('alice', $actor->getUsername());
        $this->assertSame('mcp', $actor->getSource());
        $this->assertSame('Claude', $actor->getConnector());
        $this->assertSame('Catalog Managers', $actor->getRoleName());
        $this->assertSame('mcp/update_product', $actor->getAction());
    }

    /**
     * An admin-user token has no client; it says so rather than leaving the connector blank.
     *
     * @return void
     */
    public function testAdminTokenIsNamedAsSuch(): void
    {
        $identity = new Identity(UserContextInterface::USER_TYPE_ADMIN, 7, 'admin#7', []);

        $actor = $this->recorder($this->user('alice', 'Administrators'))->actor($identity, 'set_config');

        $this->assertSame('admin token', $actor->getConnector());
        $this->assertSame('Administrators', $actor->getRoleName());
    }

    /**
     * An integration is no admin user: no user id, and its own resource set stands in for a role.
     *
     * @return void
     */
    public function testIntegrationIsRecordedWithoutAnAdminUser(): void
    {
        $identity = new Identity(UserContextInterface::USER_TYPE_INTEGRATION, 3, 'Reporting', [], true, 'Reporting');
        $factory = $this->createMock(UserFactory::class);
        $factory->expects($this->never())->method('create');

        $actor = (new AdminActivityRecorder(new ExternalActorScope(), $factory))->actor($identity, 'delete_product');

        $this->assertNull($actor->getUserId());
        $this->assertSame('integration:Reporting', $actor->getUsername());
        $this->assertSame('Reporting', $actor->getConnector());
        $this->assertSame('Integration "Reporting"', $actor->getRoleName());
    }

    /**
     * The tool runs inside the scope, and its result comes back unchanged.
     *
     * @return void
     */
    public function testRunPassesTheWorkThroughTheScope(): void
    {
        $scope = $this->createMock(ExternalActorScope::class);
        $scope->expects($this->once())->method('run')
            ->with(
                $this->callback(static fn (ExternalActor $actor): bool => $actor->getAction() === 'mcp/update_product'),
                $this->isType('callable')
            )
            ->willReturnCallback(static fn (ExternalActor $actor, callable $work): mixed => $work());
        $identity = new Identity(UserContextInterface::USER_TYPE_INTEGRATION, 3, 'Reporting', [], true, 'Reporting');

        $result = (new AdminActivityRecorder($scope, $this->createMock(UserFactory::class)))
            ->run($identity, 'update_product', static fn (): array => ['ok' => true]);

        $this->assertSame(['ok' => true], $result);
    }

    /**
     * @param User $user
     * @return AdminActivityRecorder
     */
    private function recorder(User $user): AdminActivityRecorder
    {
        $factory = $this->createMock(UserFactory::class);
        $factory->method('create')->willReturn($user);

        return new AdminActivityRecorder(new ExternalActorScope(), $factory);
    }

    /**
     * @param string $username
     * @param string $roleName
     * @return User
     */
    private function user(string $username, string $roleName): User
    {
        $role = $this->createMock(Role::class);
        $role->method('getRoleName')->willReturn($roleName);
        $user = $this->createMock(User::class);
        $user->method('load')->willReturnSelf();
        $user->method('getUserName')->willReturn($username);
        $user->method('getRole')->willReturn($role);

        return $user;
    }
}
