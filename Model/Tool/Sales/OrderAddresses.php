<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Finds an order's two addresses.
 *
 * The billing address is on OrderInterface; the shipping address is not. It
 * lives on the shipping assignment extension attribute, and a virtual order has
 * none at all, so every step of reaching it has to be guarded. Doing that in
 * one place keeps the guard out of each tool that needs the address — and a
 * tool that writes one must never fall back to the billing address when the
 * shipping address is missing.
 */
class OrderAddresses
{
    /**
     * @param OrderInterface $order
     * @return OrderAddressInterface|null
     */
    public function billing(OrderInterface $order): ?OrderAddressInterface
    {
        return $order->getBillingAddress();
    }

    /**
     * @param OrderInterface $order
     * @return OrderAddressInterface|null Null on a virtual order, which has none.
     */
    public function shipping(OrderInterface $order): ?OrderAddressInterface
    {
        $extension = $order->getExtensionAttributes();
        if ($extension === null || !method_exists($extension, 'getShippingAssignments')) {
            return null;
        }

        $assignments = $extension->getShippingAssignments();
        if (!is_array($assignments) || $assignments === []) {
            return null;
        }

        return $assignments[0]->getShipping()?->getAddress();
    }
}
