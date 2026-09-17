<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change fields on an existing customer account.
 */
class UpdateCustomer extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerProfileArguments $profile
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerProfileArguments $profile,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update a customer account. Only the fields you pass are changed. Changing '
            . 'new_email moves the account to that address and invalidates any pending password '
            . 'reset link. Addresses are not touched here — use the create, update and delete '
            . 'customer address tools. Does not change the password; use initiate_password_reset.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'new_email' => [
                        'type' => 'string',
                        'description' => 'Move the account to this email address. Named separately '
                            . 'from "email" so that looking an account up by address cannot be '
                            . 'confused with renaming it.',
                    ],
                ],
                $this->profile->schemaProperties()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Customer::manage';
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
    protected function isIdempotent(): bool
    {
        // Only the fields passed are written, to the values passed.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        // The existing record is loaded and mutated rather than rebuilt, so a
        // field that was not passed keeps its value. It also carries the
        // customer's addresses, which matters: Magento treats the address list
        // on a saved customer as authoritative and deletes any address missing
        // from it.
        $customer = $this->locator->locate(
            $this->optionalInt($arguments, 'customer_id'),
            $this->optionalString($arguments, 'email'),
            $this->optionalInt($arguments, 'website_id')
        );

        $changed = $this->profile->applyTo($customer, $arguments);

        $newEmail = $this->optionalString($arguments, 'new_email');
        if ($newEmail !== null) {
            $customer->setEmail($newEmail);
            $changed[] = 'email';
        }

        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides the customer identifier.')
            );
        }

        $saved = $this->customerRepository->save($customer);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toSummary($saved);
    }
}
