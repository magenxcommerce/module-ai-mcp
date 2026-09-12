<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;

/**
 * Create a customer account.
 */
class CreateCustomer extends AbstractTool
{
    /**
     * @param AccountManagementInterface $accountManagement
     * @param CustomerInterfaceFactory $customerFactory
     * @param CustomerProfileArguments $profile
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly AccountManagementInterface $accountManagement,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly CustomerProfileArguments $profile,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_customer';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a customer account. Magento sends its account-creation email, so this is '
            . 'visible to the customer. Omit password and the account is created without one: the '
            . 'customer sets it through the email link, or through initiate_password_reset. A '
            . 'password that is given is checked against the store\'s password policy. Add '
            . 'addresses afterwards with create_customer_address.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'email' => ['type' => 'string', 'description' => 'The account\'s email address.'],
                    'password' => [
                        'type' => 'string',
                        'description' => 'Optional initial password. Omit to let the customer set '
                            . 'their own; Magento rejects one that is too weak or equal to the email.',
                    ],
                    'website_id' => [
                        'type' => 'integer',
                        'description' => 'Website the account belongs to. Defaults to the store\'s '
                            . 'default website. list_stores reports the ids.',
                    ],
                ],
                $this->profile->schemaProperties()
            ),
            'required' => ['email', 'firstname', 'lastname'],
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
    public function execute(array $arguments): array
    {
        // Presence is enforced here; the values themselves are applied by the
        // shared profile handler along with every other field.
        $this->requireString($arguments, 'firstname');
        $this->requireString($arguments, 'lastname');

        $customer = $this->customerFactory->create();
        $customer->setEmail($this->requireString($arguments, 'email'));

        $websiteId = $this->optionalInt($arguments, 'website_id');
        if ($websiteId !== null) {
            $customer->setWebsiteId($websiteId);
        }

        $this->profile->applyTo($customer, $arguments);

        $password = $this->optionalString($arguments, 'password');
        $created = $this->accountManagement->createAccount($customer, $password);

        return [
            'created' => true,
            'tool' => $this->getName(),
            'password_set' => $password !== null,
        ] + $this->projector->toSummary($created);
    }
}
