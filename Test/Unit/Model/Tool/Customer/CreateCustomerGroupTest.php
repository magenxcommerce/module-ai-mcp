<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\Customer\CreateCustomerGroup;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Creating a customer group.
 *
 * `tax_class_id` is required rather than defaulted, and that is the whole
 * substance of this tool. A group created without one is not a group that fails
 * to save — it is a group whose members are taxed by whatever Magento falls
 * back to, on every order they place, with nothing anywhere reporting a
 * problem. Defaulting it would be this server picking a tax treatment for a
 * store it knows nothing about.
 *
 * @see CreateCustomerGroup::execute
 */
class CreateCustomerGroupTest extends TestCase
{
    private GroupInterfaceFactory&MockObject $groupFactory;
    private GroupRepositoryInterface&MockObject $groupRepository;
    private CreateCustomerGroup $tool;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        GeneratedFactory::ensure(GroupInterfaceFactory::class);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->groupFactory = $this->createMock(GroupInterfaceFactory::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);

        $this->tool = new CreateCustomerGroup($this->groupFactory, $this->groupRepository);
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheGroupResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Customer::group', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testTheTaxClassIsRequiredBySchemaAndNotDefaulted(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertContains('tax_class_id', $schema['required']);
        $this->assertArrayNotHasKey('default', $schema['properties']['tax_class_id']);
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * @return void
     */
    public function testAGroupIsCreatedWithBothFields(): void
    {
        $group = $this->createMock(GroupInterface::class);
        $group->expects($this->once())->method('setCode')->with('Wholesale');
        $group->expects($this->once())->method('setTaxClassId')->with(3);
        $group->method('getId')->willReturn(7);
        $group->method('getCode')->willReturn('Wholesale');
        $group->method('getTaxClassId')->willReturn(3);

        $this->groupFactory->method('create')->willReturn($group);
        $this->groupRepository->method('save')->willReturn($group);

        $result = $this->tool->execute(['code' => 'Wholesale', 'tax_class_id' => 3]);

        $this->assertTrue($result['created']);
        $this->assertSame(7, $result['group_id']);
        $this->assertSame(3, $result['tax_class_id']);
    }

    /**
     * @return void
     */
    public function testAMissingTaxClassIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->groupFactory->method('create')->willReturn($this->createMock(GroupInterface::class));
        $this->groupRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "tax_class_id" argument must be a whole number.');

        $this->tool->execute(['code' => 'Wholesale']);
    }

    /**
     * @return void
     */
    public function testABlankCodeIsRefusedAsAMissingField(): void
    {
        $this->groupFactory->method('create')->willReturn($this->createMock(GroupInterface::class));
        $this->groupRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "code" argument is required.');

        $this->tool->execute(['code' => '  ', 'tax_class_id' => 3]);
    }
}
