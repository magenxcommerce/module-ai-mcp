<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;

/**
 * Correct the billing or shipping address on an order.
 *
 * The address is reached through the order rather than by its own id. Magento's
 * address repository saves whatever parent_id the object it is handed carries,
 * so an address id supplied by the caller could belong to a different order
 * entirely — the same trap update_rma_item guards against. Naming the order and
 * the side of it removes the possibility.
 */
class UpdateOrderAddress extends AbstractTool
{
    private const TYPE_BILLING = 'billing';
    private const TYPE_SHIPPING = 'shipping';

    /**
     * @param OrderLocator $locator
     * @param OrderAddresses $addresses
     * @param OrderAddressRepositoryInterface $addressRepository
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly OrderAddresses $addresses,
        private readonly OrderAddressRepositoryInterface $addressRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_order_address';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Correct the billing or shipping address on an existing order — a mistyped street or '
            . 'postcode on something not yet shipped. Only the fields you pass are changed; there '
            . 'is no way to clear one to empty here. This edits the order record alone: it does not '
            . 'move a shipment that already exists or the label printed for it, does not '
            . 'recalculate shipping or tax, and tells the customer nothing. Changing country_id '
            . 'without the matching region_id leaves an address the storefront considers invalid.';
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
                    'address_type' => [
                        'type' => 'string',
                        'enum' => [self::TYPE_BILLING, self::TYPE_SHIPPING],
                        'description' => 'Which of the order\'s two addresses to change. A virtual '
                            . 'order has no shipping address.',
                    ],
                    'firstname' => ['type' => 'string', 'description' => 'Given name.'],
                    'lastname' => ['type' => 'string', 'description' => 'Family name.'],
                    'company' => ['type' => 'string', 'description' => 'Company name.'],
                    'street' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Street lines, in order. Replaces every existing line, so '
                            . 'pass the whole address rather than only the one being corrected.',
                    ],
                    'city' => ['type' => 'string', 'description' => 'City.'],
                    'region' => [
                        'type' => 'string',
                        'description' => 'Region or state name. For a country whose regions Magento '
                            . 'knows, pass region_id as well or the storefront will not match it.',
                    ],
                    'region_id' => [
                        'type' => 'integer',
                        'description' => 'Magento\'s numeric region id, required for countries with '
                            . 'a fixed region list such as US, CA and IN.',
                    ],
                    'postcode' => ['type' => 'string', 'description' => 'Postal code.'],
                    'country_id' => [
                        'type' => 'string',
                        'description' => 'Two-letter ISO country code, e.g. "DE".',
                    ],
                    'telephone' => ['type' => 'string', 'description' => 'Phone number.'],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Address e-mail. This is the copy stored on the order '
                            . 'address; it does not change the customer account.',
                    ],
                ]
            ),
            'required' => ['address_type'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::actions_edit';
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
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        $addressType = $this->requireString($arguments, 'address_type');
        $address = $this->resolveAddress($order, $addressType);

        $changed = $this->applyFields($address, $arguments);
        if ($changed === []) {
            throw new LocalizedException(
                __('Pass at least one address field to change; nothing was supplied.')
            );
        }

        $saved = $this->addressRepository->save($address);

        return [
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'address_type' => $addressType,
            'address_id' => (int) $saved->getEntityId(),
            'changed_fields' => $changed,
            'address' => [
                'firstname' => $saved->getFirstname(),
                'lastname' => $saved->getLastname(),
                'company' => $saved->getCompany(),
                'street' => $saved->getStreet(),
                'city' => $saved->getCity(),
                'region' => $saved->getRegion(),
                'region_id' => $saved->getRegionId() === null ? null : (int) $saved->getRegionId(),
                'postcode' => $saved->getPostcode(),
                'country_id' => $saved->getCountryId(),
                'telephone' => $saved->getTelephone(),
                'email' => $saved->getEmail(),
            ],
        ];
    }

    /**
     * The named side of this order, never another order's address.
     *
     * @param OrderInterface $order
     * @param string $addressType
     * @return OrderAddressInterface
     * @throws LocalizedException
     */
    private function resolveAddress(OrderInterface $order, string $addressType): OrderAddressInterface
    {
        if ($addressType !== self::TYPE_BILLING && $addressType !== self::TYPE_SHIPPING) {
            throw new LocalizedException(__(
                'The "address_type" argument must be "%1" or "%2".',
                self::TYPE_BILLING,
                self::TYPE_SHIPPING
            ));
        }

        $address = $addressType === self::TYPE_BILLING
            ? $this->addresses->billing($order)
            : $this->addresses->shipping($order);

        if ($address === null) {
            throw new LocalizedException(__(
                'Order %1 has no %2 address. An order of only virtual or downloadable products '
                    . 'never has a shipping address.',
                $order->getIncrementId(),
                $addressType
            ));
        }

        return $address;
    }

    /**
     * Set the fields that were supplied, and report which those were.
     *
     * Omitted means unchanged: a loaded address is being mutated, so a field
     * this does not touch keeps the value already on the order.
     *
     * @param OrderAddressInterface $address
     * @param array<string, mixed> $arguments
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function applyFields(OrderAddressInterface $address, array $arguments): array
    {
        $changed = [];

        $setters = [
            'firstname' => static fn (string $v) => $address->setFirstname($v),
            'lastname' => static fn (string $v) => $address->setLastname($v),
            'company' => static fn (string $v) => $address->setCompany($v),
            'city' => static fn (string $v) => $address->setCity($v),
            'region' => static fn (string $v) => $address->setRegion($v),
            'postcode' => static fn (string $v) => $address->setPostcode($v),
            'country_id' => static fn (string $v) => $address->setCountryId($v),
            'telephone' => static fn (string $v) => $address->setTelephone($v),
            'email' => static fn (string $v) => $address->setEmail($v),
        ];

        foreach ($setters as $field => $setter) {
            $value = $this->optionalString($arguments, $field);
            if ($value !== null) {
                $setter($value);
                $changed[] = $field;
            }
        }

        $regionId = $this->optionalInt($arguments, 'region_id');
        if ($regionId !== null) {
            $address->setRegionId($regionId);
            $changed[] = 'region_id';
        }

        if (array_key_exists('street', $arguments)) {
            $address->setStreet($this->streetLines($arguments));
            $changed[] = 'street';
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function streetLines(array $arguments): array
    {
        $lines = [];
        foreach ($this->optionalArray($arguments, 'street') as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }
            $lines[] = trim($line);
        }

        if ($lines === []) {
            throw new LocalizedException(
                __('The "street" argument must hold at least one non-empty line.')
            );
        }

        return $lines;
    }
}
