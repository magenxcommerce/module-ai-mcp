<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\Gdpr\Model\DsrRequest;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * One data-subject request, with the person it is about.
 *
 * The stored row names a customer only by id, which is no use to anyone
 * deciding what to do about it — and the whole point of
 * {@see ApproveGdprRequest}'s e-mail check is that the caller can see whose
 * data is at stake before confirming. So the customer is resolved here, and a
 * customer who no longer resolves is reported as such rather than omitted: on
 * an erasure queue, "already anonymized" is an answer.
 */
class RequestProjector
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @param DsrRequest $request
     * @return array<string, mixed>
     */
    public function toArray(DsrRequest $request): array
    {
        $customerId = (int) $request->getData('customer_id');

        return [
            'request_id' => (int) $request->getId(),
            'customer_id' => $customerId,
            'customer_email' => $this->emailOf($customerId),
            'type' => $request->getData('type'),
            'status' => $request->getData('status'),
            'customer_note' => $request->getData('customer_note'),
            'admin_note' => $request->getData('admin_note'),
            'requested_at' => $request->getData('requested_at'),
            'resolved_at' => $request->getData('resolved_at'),
        ];
    }

    /**
     * @param int $customerId
     * @return string|null
     */
    public function emailOf(int $customerId): ?string
    {
        if ($customerId === 0) {
            return null;
        }

        try {
            return (string) $this->customerRepository->getById($customerId)->getEmail();
        } catch (NoSuchEntityException) {
            // Deleted, or already anonymized by an earlier approval.
            return null;
        }
    }
}
