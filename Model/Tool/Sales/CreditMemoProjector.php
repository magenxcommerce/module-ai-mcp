<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;

/**
 * Shrinks a credit memo to something worth sending to a model.
 */
class CreditMemoProjector extends AbstractDocumentProjector
{
    private const STATE_LABELS = [1 => 'open', 2 => 'refunded', 3 => 'canceled'];

    /**
     * @param CreditmemoInterface $document
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
            // 1 = open, 2 = refunded, 3 = canceled.
            'state' => $state,
            'state_label' => $state === null ? null : (self::STATE_LABELS[$state] ?? null),
            'grand_total' => $this->money($document->getGrandTotal()),
            'base_grand_total' => $this->money($document->getBaseGrandTotal()),
            'transaction_id' => $document->getTransactionId(),
            'created_at' => $document->getCreatedAt(),
        ];
    }

    /**
     * @param CreditmemoInterface $document
     * @return array<string, mixed>
     */
    public function toDetail(object $document): array
    {
        $detail = $this->toSummary($document);
        $detail['invoice_id'] = $document->getInvoiceId() === null ? null : (int) $document->getInvoiceId();
        $detail['order_currency'] = $document->getOrderCurrencyCode();
        $detail['base_currency'] = $document->getBaseCurrencyCode();
        $detail['totals'] = [
            'subtotal' => $this->money($document->getSubtotal()),
            'shipping_amount' => $this->money($document->getShippingAmount()),
            'tax_amount' => $this->money($document->getTaxAmount()),
            'discount_amount' => $this->money($document->getDiscountAmount()),
            // What was added to or taken off the refund by hand, which is the
            // usual reason a credit memo total is not the sum of its lines.
            'adjustment' => $this->money($document->getAdjustment()),
            'adjustment_positive' => $this->money($document->getAdjustmentPositive()),
            'adjustment_negative' => $this->money($document->getAdjustmentNegative()),
        ];
        $detail['items'] = array_map(
            fn (CreditmemoItemInterface $item): array => [
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
