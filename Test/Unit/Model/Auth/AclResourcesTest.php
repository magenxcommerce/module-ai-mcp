<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Auth;

use Magenx\AiMcp\Model\Auth\AclResources;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * An admin's rules live on the group role, not on the admin's own membership row.
 *
 * Reading the membership row is what refused every OAuth-connected admin with
 * "missing Magenx_AiMcp::server", Administrators included.
 *
 * @see AclResources
 */
class AclResourcesTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        GeneratedFactory::ensure(RoleCollectionFactory::class);
    }

    /**
     * @return void
     */
    public function testAdminResourcesComeFromTheParentGroupRole(): void
    {
        $retriever = $this->createMock(AclRetriever::class);
        $retriever->expects($this->never())->method('getAllowedResourcesByUser');
        $retriever->expects($this->once())->method('getAllowedResourcesByRole')->with(1)
            ->willReturn(['Magento_Backend::all']);

        $resources = (new AclResources($retriever, $this->roles(new DataObject(['role_id' => 2, 'parent_id' => 1]))))
            ->forUser(UserContextInterface::USER_TYPE_ADMIN, 1);

        $this->assertSame(['Magento_Backend::all'], $resources);
    }

    /**
     * @return void
     */
    public function testAdminWithoutARoleGetsNothing(): void
    {
        $retriever = $this->createMock(AclRetriever::class);
        $retriever->expects($this->never())->method('getAllowedResourcesByRole');

        $resources = (new AclResources($retriever, $this->roles(new DataObject([]))))
            ->forUser(UserContextInterface::USER_TYPE_ADMIN, 9);

        $this->assertSame([], $resources);
    }

    /**
     * An integration's resources are on its own row, which the stock retriever reads correctly.
     *
     * @return void
     */
    public function testIntegrationUsesItsOwnRole(): void
    {
        $retriever = $this->createMock(AclRetriever::class);
        $retriever->expects($this->once())->method('getAllowedResourcesByUser')
            ->with(UserContextInterface::USER_TYPE_INTEGRATION, 3)
            ->willReturn(['Magenx_AiMcp::server']);
        $factory = $this->createMock(RoleCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $resources = (new AclResources($retriever, $factory))->forUser(UserContextInterface::USER_TYPE_INTEGRATION, 3);

        $this->assertSame(['Magenx_AiMcp::server'], $resources);
    }

    /**
     * @return void
     */
    public function testAFailureGrantsNothing(): void
    {
        $retriever = $this->createMock(AclRetriever::class);
        $retriever->method('getAllowedResourcesByUser')->willThrowException(new \RuntimeException('no role'));

        $resources = (new AclResources($retriever, $this->createMock(RoleCollectionFactory::class)))
            ->forUser(UserContextInterface::USER_TYPE_INTEGRATION, 3);

        $this->assertSame([], $resources);
    }

    /**
     * @param DataObject $membership
     * @return RoleCollectionFactory
     */
    private function roles(DataObject $membership): RoleCollectionFactory
    {
        $collection = $this->createMock(RoleCollection::class);
        $collection->method('setUserFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($membership);
        $factory = $this->createMock(RoleCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
