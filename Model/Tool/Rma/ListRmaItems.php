<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\RmaItemManagementInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Read the lines of a return.
 */
class ListRmaItems extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RmaItemManagementInterface $itemManagement
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RmaItemManagementInterface $itemManagement,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_rma_items';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the lines of a return: what the customer asked to send back, how much of it '
            . 'was approved, how much actually arrived, and the condition it was in. Each line '
            . 'points at an order line through order_item_id, which get_order reports. These '
            . 'quantities are the return\'s own record and are separate from the order\'s — '
            . 'refunding is still create_credit_memo against the order.';
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
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        return $this->searchEnvelopeSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $result = $this->itemManagement->getList(
            (int) $rma->getEntityId(),
            $this->searchCriteriaBuilder->create()
        );

        return [
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'total_count' => $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (ItemInterface $item): array => [
                    'item_id' => $item->getEntityId(),
                    'order_item_id' => $item->getOrderItemId(),
                    'qty_requested' => $item->getQtyRequested(),
                    'qty_approved' => $item->getQtyApproved(),
                    'qty_returned' => $item->getQtyReturned(),
                    'condition_id' => $item->getConditionId(),
                    'created_at' => $item->getCreatedAt(),
                    'updated_at' => $item->getUpdatedAt(),
                ],
                array_values($result->getItems())
            ),
        ];
    }
}
