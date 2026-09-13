<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Shrinks an order to something worth sending to a model.
 *
 * A loaded order serialises to well over a thousand lines once its items,
 * addresses, payment and status history are included, so lists return a short
 * summary and the detail view is opted into through get_order.
 *
 * The detail view carries one thing the raw order does not: per-item
 * invoiceable, shippable and refundable quantities. Magento stores the five
 * counters those derive from (ordered, invoiced, shipped, refunded, canceled)
 * and expects the caller to do the arithmetic. An agent doing that arithmetic
 * itself is an agent that will eventually refund a quantity that was never
 * invoiced, so the numbers a write tool actually accepts are computed here.
 */
class OrderProjector
{
    /**
     * The identifying and financial headline of an order.
     *
     * @param OrderInterface $order
     * @return array<string, mixed>
     */
    public function toSummary(OrderInterface $order): array
    {
        return [
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'store_id' => (int) $order->getStoreId(),
            'customer_email' => $order->getCustomerEmail(),
            'customer_name' => trim(
                (string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()
            ) ?: null,
            'customer_id' => $order->getCustomerId() === null ? null : (int) $order->getCustomerId(),
            'is_guest' => (bool) $order->getCustomerIsGuest(),
            'currency' => $order->getOrderCurrencyCode(),
            'grand_total' => $this->money($order->getGrandTotal()),
            'total_paid' => $this->money($order->getTotalPaid()),
            'total_refunded' => $this->money($order->getTotalRefunded()),
            'total_due' => $this->money($order->getTotalDue()),
            'created_at' => $order->getCreatedAt(),
            'updated_at' => $order->getUpdatedAt(),
        ];
    }

    /**
     * The summary plus items, addresses, payment, totals and what can still be
     * done to the order.
     *
     * @param OrderInterface $order
     * @return array<string, mixed>
     */
    public function toDetail(OrderInterface $order): array
    {
        $detail = $this->toSummary($order);
        $detail['store_name'] = $order->getStoreName();
        $detail['customer_note'] = $order->getCustomerNote();
        $detail['base_currency'] = $order->getBaseCurrencyCode();
        $detail['totals'] = [
            'subtotal' => $this->money($order->getSubtotal()),
            'subtotal_invoiced' => $this->money($order->getSubtotalInvoiced()),
            'subtotal_refunded' => $this->money($order->getSubtotalRefunded()),
            'total_invoiced' => $this->money($order->getTotalInvoiced()),
            'total_canceled' => $this->money($order->getTotalCanceled()),
            'total_offline_refunded' => $this->money($order->getTotalOfflineRefunded()),
            'total_online_refunded' => $this->money($order->getTotalOnlineRefunded()),
            'total_qty_ordered' => $this->money($order->getTotalQtyOrdered()),
        ];

        $payment = $order->getPayment();
        if ($payment !== null) {
            $detail['payment'] = [
                'method' => $payment->getMethod(),
                'amount_ordered' => $this->money($payment->getAmountOrdered()),
                'amount_paid' => $this->money($payment->getAmountPaid()),
                'amount_refunded' => $this->money($payment->getAmountRefunded()),
                'last_trans_id' => $payment->getLastTransId(),
            ];
        }

        $detail['billing_address'] = $this->address($order->getBillingAddress());
        $detail['shipping_address'] = $this->address($this->findShippingAddress($order));

        $items = [];
        foreach ($order->getItems() ?? [] as $item) {
            // A configurable order carries both the parent and its simple child;
            // the child holds no independent quantity to act on and listing it
            // invites a write against the wrong order_item_id.
            if ($item->getParentItemId() !== null) {
                continue;
            }
            $items[] = $this->item($item);
        }
        $detail['items'] = $items;

        return $detail;
    }

    /**
     * One order line, with the quantities each write tool will accept.
     *
     * @param OrderItemInterface $item
     * @return array<string, mixed>
     */
    private function item(OrderItemInterface $item): array
    {
        $ordered = (float) $item->getQtyOrdered();
        $invoiced = (float) $item->getQtyInvoiced();
        $shipped = (float) $item->getQtyShipped();
        $refunded = (float) $item->getQtyRefunded();
        $canceled = (float) $item->getQtyCanceled();

        return [
            'order_item_id' => (int) $item->getItemId(),
            'sku' => $item->getSku(),
            'name' => $item->getName(),
            'price' => $this->money($item->getPrice()),
            'row_total' => $this->money($item->getRowTotal()),
            'qty_ordered' => $ordered,
            'qty_invoiced' => $invoiced,
            'qty_shipped' => $shipped,
            'qty_refunded' => $refunded,
            'qty_canceled' => $canceled,
            // What create_invoice, create_shipment and create_credit_memo may
            // still be given for this line. Magento permits shipping before
            // invoicing, so the shippable figure is not bounded by qty_invoiced.
            'qty_invoiceable' => max(0.0, $ordered - $invoiced - $canceled),
            'qty_shippable' => max(0.0, $ordered - $shipped - $canceled),
            'qty_refundable' => max(0.0, $invoiced - $refunded),
        ];
    }

    /**
     * @param OrderAddressInterface|null $address
     * @return array<string, mixed>|null
     */
    private function address(?OrderAddressInterface $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'name' => trim((string) $address->getFirstname() . ' ' . (string) $address->getLastname()) ?: null,
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
    }

    /**
     * The shipping address, which OrderInterface does not expose directly.
     *
     * It lives on the shipping assignment extension attribute, and a virtual
     * order has none at all, so every step here is guarded.
     *
     * @param OrderInterface $order
     * @return OrderAddressInterface|null
     */
    private function findShippingAddress(OrderInterface $order): ?OrderAddressInterface
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

    /**
     * Magento returns monetary columns as strings; a model reads a number more
     * reliably than "15.0000", and null must survive as null rather than 0.0.
     *
     * @param string|float|int|null $value
     * @return float|null
     */
    private function money(string|float|int|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
