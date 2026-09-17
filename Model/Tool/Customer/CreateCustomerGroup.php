<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Create a customer group.
 *
 * `tax_class_id` is required rather than defaulted. A group carries the tax
 * class every customer in it is taxed under, and guessing it wrong is not an
 * error anybody sees — it is a quietly wrong tax rate on every order those
 * customers place. list_customer_groups reports the tax class of each existing
 * group, which is the cheapest way to find the right id.
 *
 * Group codes are unique in Magento, so a duplicate is refused by the
 * repository. That refusal is translated here into a message that says what to
 * do about it rather than surfacing an input exception.
 */
class CreateCustomerGroup extends AbstractTool
{
    /**
     * @param GroupInterfaceFactory $groupFactory
     * @param GroupRepositoryInterface $groupRepository
     */
    public function __construct(
        private readonly GroupInterfaceFactory $groupFactory,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_customer_group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a customer group. The tax class must be given: it decides how everyone in '
            . 'the group is taxed, and a wrong one produces wrong tax on every order rather than '
            . 'an error. Use list_customer_groups to see the tax classes the existing groups use. '
            . 'Group codes must be unique.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'Name of the group as it appears in the admin. Must be unique.',
                ],
                'tax_class_id' => [
                    'type' => 'integer',
                    'description' => 'Tax class every customer in the group is taxed under, as '
                        . 'list_customer_groups reports it for the existing groups.',
                ],
            ],
            'required' => ['code', 'tax_class_id'],
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
        // Adds a group; no existing group or customer is touched.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        // requireString() already trims and refuses a blank, so a name of
        // nothing but spaces is turned away before anything is built.
        $group = $this->groupFactory->create();
        $group->setCode($this->requireString($arguments, 'code'));
        $group->setTaxClassId($this->requireInt($arguments, 'tax_class_id'));

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
            'created' => true,
            'tool' => $this->getName(),
            'group_id' => (int) $saved->getId(),
            'code' => $saved->getCode(),
            'tax_class_id' => $saved->getTaxClassId() === null ? null : (int) $saved->getTaxClassId(),
            'tax_class_name' => $saved->getTaxClassName(),
        ];
    }
}
