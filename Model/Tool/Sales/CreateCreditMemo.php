<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;

/**
 * Refund an order or one of its invoices by raising a credit memo.
 *
 * The only tool in this module that can move money out of the merchant's
 * account, which it does when `refund_online` is set against an invoice. Every
 * other path records the refund in Magento and leaves the payment gateway
 * alone. Both are irreversible: Magento has no operation that undoes a credit
 * memo.
 */
class CreateCreditMemo extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param LineItemArguments $lineItems
     * @param RefundOrderInterface $refundOrder
     * @param RefundInvoiceInterface $refundInvoice
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param CreditmemoItemCreationInterfaceFactory $itemFactory
     * @param CreditmemoCommentCreationInterfaceFactory $commentFactory
     * @param CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly LineItemArguments $lineItems,
        private readonly RefundOrderInterface $refundOrder,
        private readonly RefundInvoiceInterface $refundInvoice,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly CreditmemoItemCreationInterfaceFactory $itemFactory,
        private readonly CreditmemoCommentCreationInterfaceFactory $commentFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_credit_memo';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Refund an order by raising a credit memo. This cannot be undone. Omit items to '
            . 'refund everything still refundable, or pass items for specific lines — call '
            . 'get_order first and read each line\'s qty_refundable, and search_credit_memos to '
            . 'see what was already refunded. With invoice_id and refund_online true the refund '
            . 'is sent to the payment gateway and real money leaves the merchant account; '
            . 'otherwise the credit memo is recorded offline and no money moves. Shipping and '
            . 'manual adjustments are refunded through shipping_amount, adjustment_positive and '
            . 'adjustment_negative.';
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
                    'invoice_id' => [
                        'type' => 'integer',
                        'description' => 'Refund against this invoice rather than the order as a '
                            . 'whole. Required for an online refund. search_invoices reports it as '
                            . 'entity_id.',
                    ],
                    'refund_online' => [
                        'type' => 'boolean',
                        'description' => 'Send the refund to the payment gateway, moving real '
                            . 'money. Requires invoice_id and a payment method that supports it. '
                            . 'Default false, which records the credit memo offline.',
                    ],
                    'items' => $this->lineItems->schemaProperty(
                        'Lines to refund. Omit to refund every remaining refundable quantity.'
                    ),
                    'shipping_amount' => [
                        'type' => 'number',
                        'description' => 'Shipping to refund, in the order currency. Omit to let '
                            . 'Magento decide from the lines being refunded.',
                    ],
                    'adjustment_positive' => [
                        'type' => 'number',
                        'description' => 'Extra amount to refund on top of the lines ("adjustment '
                            . 'refund" in admin).',
                    ],
                    'adjustment_negative' => [
                        'type' => 'number',
                        'description' => 'Amount to withhold from the refund ("adjustment fee" in admin).',
                    ],
                    'notify_customer' => [
                        'type' => 'boolean',
                        'description' => 'Email the credit memo to the customer. Default false.',
                    ],
                    'comment' => ['type' => 'string', 'description' => 'Optional comment stored on the credit memo.'],
                    'comment_visible_on_front' => [
                        'type' => 'boolean',
                        'description' => 'Show the comment in the customer\'s account. Default false.',
                    ],
                ]
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Sales::creditmemo';
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
        $order = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'order_id')
        );

        $invoiceId = $this->optionalInt($arguments, 'invoice_id');
        $refundOnline = (bool) $this->optionalBool($arguments, 'refund_online', false);
        if ($refundOnline && $invoiceId === null) {
            // Falling back to an offline refund here would report success for a
            // refund the customer never receives.
            throw new LocalizedException(__(
                'An online refund must name the invoice to refund: pass invoice_id, which '
                . 'search_invoices reports as entity_id. Without it only an offline credit memo '
                . 'can be raised.'
            ));
        }

        $items = [];
        foreach ($this->lineItems->normalize($this->optionalArray($arguments, 'items')) as $orderItemId => $qty) {
            $creation = $this->itemFactory->create();
            $creation->setOrderItemId($orderItemId);
            $creation->setQty($qty);
            $items[] = $creation;
        }

        $commentText = $this->optionalString($arguments, 'comment');
        $comment = null;
        if ($commentText !== null) {
            $comment = $this->commentFactory->create();
            $comment->setComment($commentText);
            $comment->setIsVisibleOnFront(
                $this->optionalBool($arguments, 'comment_visible_on_front', false) ? 1 : 0
            );
        }

        $creationArguments = $this->buildCreationArguments($arguments);
        $notify = (bool) $this->optionalBool($arguments, 'notify_customer', false);

        if ($invoiceId !== null) {
            $creditmemoId = (int) $this->refundInvoice->execute(
                $invoiceId,
                $items,
                $refundOnline,
                $notify,
                $comment !== null,
                $comment,
                $creationArguments
            );
        } else {
            $creditmemoId = (int) $this->refundOrder->execute(
                (int) $order->getEntityId(),
                $items,
                $notify,
                $comment !== null,
                $comment,
                $creationArguments
            );
        }

        $creditmemo = $this->creditmemoRepository->get($creditmemoId);
        $updated = $this->locator->locate(null, (int) $order->getEntityId());

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'credit_memo_id' => $creditmemoId,
            'credit_memo_increment_id' => $creditmemo->getIncrementId(),
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $updated->getIncrementId(),
            'invoice_id' => $invoiceId,
            'refunded_online' => $refundOnline,
            'grand_total' => $creditmemo->getGrandTotal() === null ? null : (float) $creditmemo->getGrandTotal(),
            'order_total_refunded' => $updated->getTotalRefunded() === null
                ? null
                : (float) $updated->getTotalRefunded(),
            'customer_notified' => $notify,
            'order_state' => $updated->getState(),
            'order_status' => $updated->getStatus(),
        ];
    }

    /**
     * The shipping and adjustment amounts, or null when none were given.
     *
     * Passing an arguments object with everything unset is not the same as
     * passing none: Magento reads an explicit zero shipping amount as "refund
     * no shipping", so the object is only built when the caller actually asked
     * for one of these.
     *
     * @param array<string, mixed> $arguments
     * @return CreditmemoCreationArgumentsInterface|null
     * @throws LocalizedException
     */
    private function buildCreationArguments(array $arguments): ?CreditmemoCreationArgumentsInterface
    {
        $shipping = $this->optionalAmount($arguments, 'shipping_amount');
        $positive = $this->optionalAmount($arguments, 'adjustment_positive');
        $negative = $this->optionalAmount($arguments, 'adjustment_negative');

        if ($shipping === null && $positive === null && $negative === null) {
            return null;
        }

        $creationArguments = $this->argumentsFactory->create();
        if ($shipping !== null) {
            $creationArguments->setShippingAmount($shipping);
        }
        if ($positive !== null) {
            $creationArguments->setAdjustmentPositive($positive);
        }
        if ($negative !== null) {
            $creationArguments->setAdjustmentNegative($negative);
        }

        return $creationArguments;
    }

    /**
     * A monetary argument: a number, not negative, or absent.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return float|null
     * @throws LocalizedException
     */
    private function optionalAmount(array $arguments, string $key): ?float
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return null;
        }

        $value = $arguments[$key];
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('The "%1" argument must be a number.', $key));
        }
        if ((float) $value < 0) {
            throw new LocalizedException(
                __('The "%1" argument cannot be negative; use adjustment_negative to withhold an amount.', $key)
            );
        }

        return (float) $value;
    }
}
