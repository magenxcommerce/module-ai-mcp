<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Rename a customer group or move it to another tax class.
 *
 * The group is loaded and mutated rather than rebuilt, so passing only `code`
 * leaves the tax class alone. Building a fresh data object from the arguments
 * would save the group with a null tax class — no error, and every customer in
 * it silently taxed differently from tomorrow.
 *
 * Changing the tax class re-taxes everyone in the group from the next order
 * onwards. It does not touch orders already placed, and it does not move any
 * customer between groups; use update_customer for that.
 */
class UpdateCustomerGroup extends AbstractTool
{
    /**
     * @param GroupRepositoryInterface $groupRepository
     */
    public function __construct(private readonly GroupRepositoryInterface $groupRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_customer_group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Rename a customer group or change the tax class it is taxed under. Only the fields '
            . 'you pass are changed. Changing the tax class affects how every customer in the group '
            . 'is taxed on future orders; orders already placed keep the tax they were charged. '
            . 'This does not move customers between groups — update_customer does that.';
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
                'code' => [
                    'type' => 'string',
                    'description' => 'New name for the group. Must be unique.',
                ],
                'tax_class_id' => [
                    'type' => 'integer',
                    'description' => 'Tax class the group is taxed under.',
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
    protected function isDestructive(): bool
    {
        // Changes a group's own fields; removes nothing and moves nobody.
        return false;
    }

    /**
     * @inheritDoc
     */
    protected function isIdempotent(): bool
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

        $changed = [];

        if (array_key_exists('code', $arguments)) {
            // requireString() trims and refuses a blank, so passing spaces
            // cannot leave the group unidentifiable in every grid that lists it.
            $group->setCode($this->requireString($arguments, 'code'));
            $changed[] = 'code';
        }

        if (array_key_exists('tax_class_id', $arguments)) {
            $group->setTaxClassId($this->requireInt($arguments, 'tax_class_id'));
            $changed[] = 'tax_class_id';
        }

        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass code, tax_class_id, or both.'));
        }

        try {
            $saved = $this->groupRepository->save($group);
        } catch (InputException $e) {
            throw new LocalizedException(__(
                'The group could not be saved: %1. Group codes must be unique and the tax class '
                . 'must exist — list_customer_groups shows both for the groups already there.',
                $e->getMessage()
            ));
        }

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'group_id' => (int) $saved->getId(),
            'code' => $saved->getCode(),
            'tax_class_id' => $saved->getTaxClassId() === null ? null : (int) $saved->getTaxClassId(),
            'tax_class_name' => $saved->getTaxClassName(),
        ];
    }
}
