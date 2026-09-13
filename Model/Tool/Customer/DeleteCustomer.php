<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * Delete a customer account.
 */
class DeleteCustomer extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Permanently delete a customer account and its addresses. This cannot be undone. '
            . 'Past orders survive as guest-style records and keep the personal data captured on '
            . 'them, so this does not by itself satisfy an erasure request. Behind its own ACL '
            . 'resource, separate from the one that allows editing customers.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Customer::delete';
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
        $customer = $this->locator->locate(
            $this->optionalInt($arguments, 'customer_id'),
            $this->optionalString($arguments, 'email'),
            $this->optionalInt($arguments, 'website_id')
        );

        // Captured before the delete so the result and the audit log name the
        // account that is now gone, rather than only the id that was passed in.
        $deleted = $this->projector->toSummary($customer);
        $addressCount = count($customer->getAddresses() ?? []);

        $this->customerRepository->delete($customer);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'addresses_deleted' => $addressCount,
        ] + $deleted;
    }
}
