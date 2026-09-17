<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;

/**
 * Add an address to a customer.
 */
class CreateCustomerAddress extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param AddressRepositoryInterface $addressRepository
     * @param AddressInterfaceFactory $addressFactory
     * @param AddressArguments $addressArguments
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly AddressArguments $addressArguments,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_customer_address';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an address to a customer account. Which fields are mandatory depends on the '
            . 'country and the store\'s configuration, and Magento names any that are missing. '
            . 'Set is_default_billing or is_default_shipping to make it the account\'s default, '
            . 'which replaces the previous one.';
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
                $this->addressArguments->schemaProperties()
            ),
            // Only the three that every stock configuration requires. Postcode,
            // region and telephone are required per country or per store
            // setting, so claiming them here would refuse addresses the store
            // would have accepted; Magento names them if they are missing.
            'required' => ['street', 'city', 'country_id'],
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
    protected function isDestructive(): bool
    {
        // Adds an address beside the ones already on the customer.
        return false;
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

        $address = $this->addressFactory->create();
        $address->setCustomerId((int) $customer->getId());

        // An address with no name of its own is shown blank in the account, so
        // the customer's own name is the sensible default for a new one.
        if (!array_key_exists('firstname', $arguments)) {
            $address->setFirstname($customer->getFirstname());
        }
        if (!array_key_exists('lastname', $arguments)) {
            $address->setLastname($customer->getLastname());
        }

        $this->addressArguments->applyTo($address, $arguments);

        $saved = $this->addressRepository->save($address);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'customer_id' => (int) $customer->getId(),
            'email' => $customer->getEmail(),
            'address' => $this->projector->address($saved),
        ];
    }
}
