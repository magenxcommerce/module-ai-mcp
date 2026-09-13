<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;

/**
 * Shrinks an invoice to something worth sending to a model.
 */
class InvoiceProjector extends AbstractDocumentProjector
{
    /** Magento's invoice states, which the API reports only as an integer. */
    public const STATE_OPEN = 1;
    public const STATE_PAID = 2;
    public const STATE_CANCELED = 3;

    private const STATE_LABELS = [
        self::STATE_OPEN => 'open',
        self::STATE_PAID => 'paid',
        self::STATE_CANCELED => 'canceled',
    ];

    /**
     * @param InvoiceInterface $document
     * @return array<string, mixed>
     */
    public function toSummary(object $document): array
    {
        $state = $document->getState() === null ? null : (int) $document->getState();

        return [
            'entity_id' => (int) $document->getEntityId(),
            'increment_id' => $document->getIncrementId(),
            'order_id' => (int) $document->getOrderId(),
            'store_id' => (int) $document->getStoreId(),
            // 1 = open, 2 = paid, 3 = canceled.
            'state' => $state,
            'state_label' => $state === null ? null : (self::STATE_LABELS[$state] ?? null),
            'grand_total' => $this->money($document->getGrandTotal()),
            'base_grand_total' => $this->money($document->getBaseGrandTotal()),
            'total_qty' => $this->money($document->getTotalQty()),
            'total_refunded' => $this->money($document->getBaseTotalRefunded()),
            'transaction_id' => $document->getTransactionId(),
            'created_at' => $document->getCreatedAt(),
        ];
    }

    /**
     * @param InvoiceInterface $document
     * @return array<string, mixed>
     */
    public function toDetail(object $document): array
    {
        $detail = $this->toSummary($document);
        $detail['order_currency'] = $document->getOrderCurrencyCode();
        $detail['base_currency'] = $document->getBaseCurrencyCode();
        $detail['totals'] = [
            'subtotal' => $this->money($document->getSubtotal()),
            'shipping_amount' => $this->money($document->getShippingAmount()),
            'tax_amount' => $this->money($document->getTaxAmount()),
            'discount_amount' => $this->money($document->getDiscountAmount()),
            'shipping_tax_amount' => $this->money($document->getShippingTaxAmount()),
        ];
        $detail['items'] = array_map(
            fn (InvoiceItemInterface $item): array => [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => $this->money($item->getQty()),
                'price' => $this->money($item->getPrice()),
                'row_total' => $this->money($item->getRowTotal()),
            ],
            array_values($document->getItems() ?? [])
        );
        $detail['comments'] = $this->comments($document->getComments());

        return $detail;
    }
}
