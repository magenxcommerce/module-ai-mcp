<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one customer account in full, including its addresses.
 */
class GetCustomer extends AbstractTool
{
    /**
     * @param CustomerLocator $locator
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly CustomerLocator $locator,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one customer by customer_id, or by email within a website: profile, group, '
            . 'confirmation state and every stored address with its address_id. This returns the '
            . 'full personal record — date of birth, tax id, postal addresses, phone numbers — so '
            . 'call it about a customer you are actually working on.';
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
        return 'Magento_Customer::manage';
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

        return $this->projector->toDetail($customer);
    }
}
