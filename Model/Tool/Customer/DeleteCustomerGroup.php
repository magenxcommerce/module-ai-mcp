<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InvalidTransitionException;

/**
 * Delete a customer group, but only an empty one.
 *
 * This looks like a one-row delete and is not. Magento does not orphan the
 * customers in a deleted group — it moves every one of them to the default
 * group, a full customer save each, firing the customer save observers each
 * time. On a group with real membership that is a long, unbounded write hidden
 * behind a call whose confirm preview says nothing but a group id.
 *
 * So the members are counted first and any at all refuse the delete, naming the
 * number. Moving them is a decision with consequences — which group, and what
 * that does to their prices and tax — and it belongs to whoever is asking, made
 * deliberately with update_customer or in the admin, not as a side effect of
 * tidying up a group.
 *
 * Magento separately refuses to delete the "NOT LOGGED IN" group and any group
 * named as a default in configuration. Those refusals are passed through with
 * their own wording rather than second-guessed here.
 */
class DeleteCustomerGroup extends AbstractTool
{
    /**
     * @param GroupRepositoryInterface $groupRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_customer_group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a customer group that has no customers in it. A group with members is '
            . 'refused, with the count: deleting it would move every one of those customers to the '
            . 'default group, which changes the prices and tax they see and is not something to do '
            . 'as a side effect. Move them first with update_customer, then delete the empty group. '
            . 'This cannot be undone.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Id of the group, as list_customer_groups reports it.',
                ],
            ],
            'required' => ['group_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Customer::group';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $groupId = $this->requireInt($arguments, 'group_id');

        try {
            $group = $this->groupRepository->getById($groupId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No customer group exists with group_id %1. Use list_customer_groups to see them.',
                $groupId
            ));
        }

        $members = $this->countMembers($groupId);
        if ($members > 0) {
            throw new LocalizedException(__(
                'Group %1 ("%2") still has %3 customer(s) in it. Deleting it would move every one '
                . 'of them to the default group and change the prices and tax they see. Move them '
                . 'first with update_customer, then delete the empty group.',
                $groupId,
                (string) $group->getCode(),
                $members
            ));
        }

        $code = $group->getCode();

        try {
            $this->groupRepository->deleteById($groupId);
        } catch (InvalidTransitionException $e) {
            // Magento reserves "NOT LOGGED IN" and any group configured as a
            // default. Its own message names which, so it is repeated as is.
            throw new LocalizedException(__('Group %1 cannot be deleted: %2', $groupId, $e->getMessage()));
        }

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'group_id' => $groupId,
            'code' => $code,
        ];
    }

    /**
     * How many customers are in the group.
     *
     * Only the total is wanted, so a page of one is asked for; the repository
     * reports the full count regardless of the page size.
     *
     * @param int $groupId
     * @return int
     */
    private function countMembers(int $groupId): int
    {
        $this->searchCriteriaBuilder->addFilter('group_id', $groupId);
        $this->searchCriteriaBuilder->setPageSize(1);
        $this->searchCriteriaBuilder->setCurrentPage(1);

        return (int) $this->customerRepository->getList($this->searchCriteriaBuilder->create())->getTotalCount();
    }
}
