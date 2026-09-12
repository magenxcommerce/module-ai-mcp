<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Sales;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

/**
 * Invoice an order, in full or line by line.
 */
class CreateInvoice extends AbstractTool
{
    /**
     * @param OrderLocator $locator
     * @param LineItemArguments $lineItems
     * @param InvoiceOrderInterface $invoiceOrder
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param InvoiceItemCreationInterfaceFactory $itemFactory
     * @param InvoiceCommentCreationInterfaceFactory $commentFactory
     */
    public function __construct(
        private readonly OrderLocator $locator,
        private readonly LineItemArguments $lineItems,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly InvoiceItemCreationInterfaceFactory $itemFactory,
        private readonly InvoiceCommentCreationInterfaceFactory $commentFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_invoice';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Invoice an order. Omit items to invoice everything still invoiceable, or pass '
            . 'items to invoice specific lines — call get_order first and read each line\'s '
            . 'qty_invoiceable. Set capture true to take payment through the payment gateway now '
            . 'where the method supports it; capture false records the invoice offline and moves '
            . 'no money. An invoice cannot be deleted once created.';
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
                    'items' => $this->lineItems->schemaProperty(
                        'Lines to invoice. Omit to invoice every remaining invoiceable quantity.'
                    ),
                    'capture' => [
                        'type' => 'boolean',
                        'description' => 'Capture payment online through the gateway. Default false, '
                            . 'which records the invoice without moving money.',
                    ],
                    'notify_customer' => [
                        'type' => 'boolean',
                        'description' => 'Email the invoice to the customer. Default false.',
                    ],
                    'comment' => ['type' => 'string', 'description' => 'Optional comment stored on the invoice.'],
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
        return 'Magento_Sales::invoice';
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

        $capture = (bool) $this->optionalBool($arguments, 'capture', false);
        $notify = (bool) $this->optionalBool($arguments, 'notify_customer', false);

        $invoiceId = (int) $this->invoiceOrder->execute(
            (int) $order->getEntityId(),
            $capture,
            $items,
            $notify,
            $comment !== null,
            $comment
        );

        $invoice = $this->invoiceRepository->get($invoiceId);
        $updated = $this->locator->locate(null, (int) $order->getEntityId());

        return [
            'applied' => true,
            'tool' => $this->getName(),
            'invoice_id' => $invoiceId,
            'invoice_increment_id' => $invoice->getIncrementId(),
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => $updated->getIncrementId(),
            'captured_online' => $capture,
            'grand_total' => $invoice->getGrandTotal() === null ? null : (float) $invoice->getGrandTotal(),
            'total_qty' => $invoice->getTotalQty() === null ? null : (float) $invoice->getTotalQty(),
            'customer_notified' => $notify,
            'order_state' => $updated->getState(),
            'order_status' => $updated->getStatus(),
        ];
    }
}
