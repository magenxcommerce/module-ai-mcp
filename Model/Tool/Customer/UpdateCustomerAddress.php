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
 * Change fields on an existing customer address.
 */
class UpdateCustomerAddress extends AbstractTool
{
    /**
     * @param AddressRepositoryInterface $addressRepository
     * @param AddressArguments $addressArguments
     * @param CustomerProjector $projector
     */
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressArguments $addressArguments,
        private readonly CustomerProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_customer_address';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update one address by address_id, which get_customer reports for every address. '
            . 'Only the fields you pass are changed. Setting is_default_billing or '
            . 'is_default_shipping moves the default here from whichever address held it.';
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
                    'address_id' => [
                        'type' => 'integer',
                        'description' => 'Id of the address to change, as reported by get_customer.',
                    ],
                ],
                $this->addressArguments->schemaProperties()
            ),
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

        $changed = $this->addressArguments->applyTo($address, $arguments);
        if ($changed === []) {
            throw new LocalizedException(__('Nothing to update: pass at least one field besides address_id.'));
        }

        $saved = $this->addressRepository->save($address);

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'address' => $this->projector->address($saved),
        ];
    }
}
