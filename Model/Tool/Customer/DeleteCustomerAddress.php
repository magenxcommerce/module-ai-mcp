<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Customer;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Remove an address from a customer.
 */
class DeleteCustomerAddress extends AbstractTool
{
    /**
     * @param AddressRepositoryInterface $addressRepository
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_customer_address';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete one customer address by address_id. This cannot be undone. Deleting the '
            . 'address that is a default leaves the account with no default of that kind until '
            . 'another is set.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'address_id' => [
                    'type' => 'integer',
                    'description' => 'Id of the address to delete, as reported by get_customer.',
                ],
            ],
            'required' => ['address_id'],
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
        $addressId = $this->requireInt($arguments, 'address_id');

        try {
            $address = $this->addressRepository->getById($addressId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No customer address exists with address_id %1.', $addressId));
        }

        // Captured before the delete so the result names the address that is
        // now gone rather than only the id that was passed in.
        $deleted = $this->projector->address($address);

        $this->addressRepository->delete($address);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'address' => $deleted,
        ];
    }
}
