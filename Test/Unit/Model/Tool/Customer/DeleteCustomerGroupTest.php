<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\Customer\DeleteCustomerGroup;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InvalidTransitionException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a customer group.
 *
 * This is the tool that looks like a one-row delete and is not. Magento does
 * not orphan the customers in a deleted group — it reassigns every one of them
 * to the default group, a full customer save each, and their prices and tax
 * change with them. The confirm preview an agent sees before all that says
 * nothing but a group id.
 *
 * So the tests below are about the count happening first, and about nothing
 * being deleted when it comes back non-zero.
 *
 * @see DeleteCustomerGroup::execute
 */
class DeleteCustomerGroupTest extends TestCase
{
    private GroupRepositoryInterface&MockObject $groupRepository;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private DeleteCustomerGroup $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteriaInterface::class));

        $this->tool = new DeleteCustomerGroup(
            $this->groupRepository,
            $this->customerRepository,
            $searchCriteriaBuilder
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheGroupResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        // Deliberately the group resource rather than the ::manage one the
        // other customer tools use.
        $this->assertSame('Magento_Customer::group', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAGroupWithCustomersIsRefusedWithTheCount(): void
    {
        $this->groupRepository->method('getById')->willReturn($this->group(5, 'Wholesale'));
        $this->membersNumber(37);
        $this->groupRepository->expects($this->never())->method('deleteById');

        try {
            $this->tool->execute(['group_id' => 5]);
            $this->fail('A group with customers must be refused.');
        } catch (LocalizedException $e) {
            // The count and the name are what make the refusal actionable.
            $this->assertStringContainsString('37 customer(s)', $e->getMessage());
            $this->assertStringContainsString('Wholesale', $e->getMessage());
            $this->assertStringContainsString('update_customer', $e->getMessage());
        }
    }

    /**
     * Even one member is enough: that customer's prices would change without
     * anybody asking for it.
     *
     * @return void
     */
    public function testASingleMemberIsEnoughToRefuse(): void
    {
        $this->groupRepository->method('getById')->willReturn($this->group(5, 'Wholesale'));
        $this->membersNumber(1);
        $this->groupRepository->expects($this->never())->method('deleteById');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['group_id' => 5]);
    }

    /**
     * @return void
     */
    public function testAnEmptyGroupIsDeletedAndNamed(): void
    {
        $this->groupRepository->method('getById')->willReturn($this->group(5, 'Trial'));
        $this->membersNumber(0);
        $this->groupRepository->expects($this->once())->method('deleteById')->with(5);

        $result = $this->tool->execute(['group_id' => 5]);

        $this->assertTrue($result['deleted']);
        $this->assertSame(5, $result['group_id']);
        $this->assertSame('Trial', $result['code']);
    }

    /**
     * @return void
     */
    public function testAnUnknownGroupIsRefusedByIdRatherThanByException(): void
    {
        $this->groupRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->customerRepository->expects($this->never())->method('getList');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No customer group exists with group_id 99');

        $this->tool->execute(['group_id' => 99]);
    }

    /**
     * Magento reserves "NOT LOGGED IN" and any group named as a default in
     * configuration. Those are its refusals to make, and its message names
     * which one applies, so it is repeated rather than guessed at here.
     *
     * @return void
     */
    public function testMagentosOwnRefusalIsPassedThroughWithItsReason(): void
    {
        $this->groupRepository->method('getById')->willReturn($this->group(0, 'NOT LOGGED IN'));
        $this->membersNumber(0);
        $this->groupRepository->method('deleteById')
            ->willThrowException(new InvalidTransitionException(__('Cannot delete group.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cannot delete group.');

        $this->tool->execute(['group_id' => 0]);
    }

    /**
     * @param int $count
     * @return void
     */
    private function membersNumber(int $count): void
    {
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getTotalCount')->willReturn($count);
        $this->customerRepository->method('getList')->willReturn($results);
    }

    /**
     * @param int $groupId
     * @param string $code
     * @return GroupInterface
     */
    private function group(int $groupId, string $code): GroupInterface
    {
        $group = $this->createMock(GroupInterface::class);
        $group->method('getId')->willReturn($groupId);
        $group->method('getCode')->willReturn($code);

        return $group;
    }
}
