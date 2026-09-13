<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\Rma\Api\Data\RMAInterface;

/**
 * Presents a return request.
 *
 * Status, reason and resolution type are stored as ids pointing at the module's
 * own lookup tables, so they are reported as ids and the list tools are what
 * turn them into labels. Resolving each one here would mean three extra queries
 * per row on a listing.
 */
class RmaProjector
{
    /**
     * @param RMAInterface $rma
     * @return array<string, mixed>
     */
    public function toArray(RMAInterface $rma): array
    {
        return [
            'rma_id' => $rma->getEntityId(),
            'increment_id' => $rma->getIncrementId(),
            'order_id' => $rma->getOrderId(),
            'store_id' => $rma->getStoreId(),
            'customer_id' => $rma->getCustomerId(),
            'customer_email' => $rma->getCustomerEmail(),
            'customer_name' => $rma->getCustomerName(),
            // Ids into the module's lookup tables; list_rma_statuses,
            // list_rma_reasons and list_rma_resolution_types give the labels.
            'status_id' => $rma->getStatusId(),
            'reason_id' => $rma->getReasonId(),
            'resolution_type_id' => $rma->getResolutionTypeId(),
            'created_at' => $rma->getCreatedAt(),
            'updated_at' => $rma->getUpdatedAt(),
        ];
    }
}
