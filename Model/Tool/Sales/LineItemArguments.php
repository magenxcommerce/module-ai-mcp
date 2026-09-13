<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magento\Framework\Exception\LocalizedException;

/**
 * Validates the `items` argument that create_invoice, create_shipment and
 * create_credit_memo all accept.
 *
 * Magento's creation services take the line items as objects carrying an order
 * item id and a quantity, and treat an empty list as "the whole order". That
 * makes a malformed item silently equivalent to a full-order operation, which
 * for a credit memo is the difference between refunding one line and refunding
 * everything — so anything that is not a well-formed item is an error here
 * rather than a discarded entry.
 */
class LineItemArguments
{
    /**
     * Normalise the raw argument into order-item-id => quantity pairs.
     *
     * @param array<mixed> $items
     * @return array<int, float> Keyed by order_item_id.
     * @throws LocalizedException
     */
    public function normalize(array $items): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new LocalizedException(
                    __('Item %1 must be an object with order_item_id and qty.', (int) $index + 1)
                );
            }

            $itemId = $item['order_item_id'] ?? null;
            if (!is_int($itemId) && !(is_string($itemId) && ctype_digit($itemId))) {
                throw new LocalizedException(
                    __('Item %1 needs an "order_item_id". get_order lists it for every line.', (int) $index + 1)
                );
            }
            $itemId = (int) $itemId;

            $qty = $item['qty'] ?? null;
            if (!is_int($qty) && !is_float($qty) && !(is_string($qty) && is_numeric($qty))) {
                throw new LocalizedException(
                    __('Item %1 needs a numeric "qty".', (int) $index + 1)
                );
            }
            $qty = (float) $qty;
            if ($qty <= 0) {
                throw new LocalizedException(
                    __('Item %1 has qty %2; a quantity must be greater than zero.', (int) $index + 1, $qty)
                );
            }

            if (isset($normalized[$itemId])) {
                throw new LocalizedException(
                    __('order_item_id %1 appears more than once; give it one combined qty.', $itemId)
                );
            }

            $normalized[$itemId] = $qty;
        }

        return $normalized;
    }

    /**
     * Schema fragment for the items argument.
     *
     * @param string $fullOperationDescription What omitting items means for this tool.
     * @return array<string, mixed>
     */
    public function schemaProperty(string $fullOperationDescription): array
    {
        return [
            'type' => 'array',
            'description' => $fullOperationDescription,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'order_item_id' => [
                        'type' => 'integer',
                        'description' => 'The line\'s order_item_id, as reported by get_order.',
                    ],
                    'qty' => ['type' => 'number', 'description' => 'Quantity for this line. Must be above zero.'],
                ],
                'required' => ['order_item_id', 'qty'],
                'additionalProperties' => false,
            ],
        ];
    }
}
