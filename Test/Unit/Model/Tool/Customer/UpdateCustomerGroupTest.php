<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\Customer\UpdateCustomerGroup;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renaming a customer group, or moving it to another tax class.
 *
 * The hazard is the field nobody passed. A group's data object carries its tax
 * class, and building a fresh one from the arguments — the obvious way to write
 * this tool — would save a rename with a null tax class. No error, no complaint
 * from Magento, and everyone in the group taxed differently from the next order
 * onwards. So the group is loaded and mutated, and the test that matters is the
 * one asserting the untouched setter is never called.
 *
 * @see UpdateCustomerGroup::execute
 */
class UpdateCustomerGroupTest extends TestCase
{
    private GroupRepositoryInterface&MockObject $groupRepository;
    private UpdateCustomerGroup $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->tool = new UpdateCustomerGroup($this->groupRepository);
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
    public function testItAdvertisesItselfAsIdempotentAndNotDestructive(): void
    {
        $annotations = $this->tool->getAnnotations();

        $this->assertTrue($annotations['idempotentHint']);
        $this->assertFalse($annotations['destructiveHint']);
    }

    /**
     * The one that stops a rename from silently re-taxing a whole group.
     *
     * @return void
     */
    public function testARenameLeavesTheTaxClassAlone(): void
    {
        $group = $this->group();
        $group->expects($this->once())->method('setCode')->with('Trade');
        $group->expects($this->never())->method('setTaxClassId');
        $this->loaded($group);

        $result = $this->tool->execute(['group_id' => 5, 'code' => 'Trade']);

        $this->assertSame(['code'], $result['changed_fields']);
    }

    /**
     * @return void
     */
    public function testATaxClassChangeLeavesTheNameAlone(): void
    {
        $group = $this->group();
        $group->expects($this->never())->method('setCode');
        $group->expects($this->once())->method('setTaxClassId')->with(4);
        $this->loaded($group);

        $result = $this->tool->execute(['group_id' => 5, 'tax_class_id' => 4]);

        $this->assertSame(['tax_class_id'], $result['changed_fields']);
    }

    /**
     * A blank name would leave the group unidentifiable in every grid that
     * lists it, and Magento accepts it. Caught as a missing required field,
     * which is the better of the two messages.
     *
     * @return void
     */
    public function testABlankCodeIsRefusedAsAMissingField(): void
    {
        $this->loaded($this->group());
        $this->groupRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "code" argument is required.');

        $this->tool->execute(['group_id' => 5, 'code' => '   ']);
    }

    /**
     * @return void
     */
    public function testAnUpdateWithNoFieldsIsRefused(): void
    {
        $this->loaded($this->group());
        $this->groupRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Nothing to update');

        $this->tool->execute(['group_id' => 5]);
    }

    /**
     * @return void
     */
    public function testAnUnknownGroupIsRefusedByIdRatherThanByException(): void
    {
        $this->groupRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No customer group exists with group_id 99');

        $this->tool->execute(['group_id' => 99, 'code' => 'Anything']);
    }

    /**
     * A duplicate code comes back as an input exception naming nothing an
     * agent can act on, so it is re-stated with the lookup that settles it.
     *
     * @return void
     */
    public function testADuplicateCodeIsReportedWithAWayToCheck(): void
    {
        $this->loaded($this->group());
        $this->groupRepository->method('save')
            ->willThrowException(new InputException(__('Group code already exists.')));

        try {
            $this->tool->execute(['group_id' => 5, 'code' => 'Wholesale']);
            $this->fail('A duplicate code must be reported.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Group code already exists.', $e->getMessage());
            $this->assertStringContainsString('list_customer_groups', $e->getMessage());
        }
    }

    /**
     * @return GroupInterface&MockObject
     */
    private function group(): GroupInterface&MockObject
    {
        $group = $this->createMock(GroupInterface::class);
        $group->method('getId')->willReturn(5);
        $group->method('getCode')->willReturn('Wholesale');
        $group->method('getTaxClassId')->willReturn(3);

        return $group;
    }

    /**
     * @param GroupInterface $group
     * @return void
     */
    private function loaded(GroupInterface $group): void
    {
        $this->groupRepository->method('getById')->willReturn($group);
        $this->groupRepository->method('save')->willReturn($group);
    }
}
