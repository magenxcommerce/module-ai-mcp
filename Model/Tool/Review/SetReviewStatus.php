<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Review;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Approve or reject a review — the moderation step.
 *
 * After the save the review's rating summary is re-aggregated, as Magento's
 * own admin does, so the product's average stars move with the decision
 * rather than waiting for the next review to trigger it.
 */
class SetReviewStatus extends AbstractTool
{
    /**
     * @param ReviewLocator $locator
     */
    public function __construct(
        private readonly ReviewLocator $locator
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_review_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set a product review\'s status: approved (shown on the storefront), pending or '
            . 'not_approved. The product\'s rating summary is recalculated straight away. The '
            . 'storefront shows the change once the page cache for that product is refreshed.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'review_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The review id.'],
                'status' => [
                    'type' => 'string',
                    'enum' => array_keys(ReviewProjector::STATUSES),
                    'description' => 'The new status.',
                ],
            ],
            'required' => ['review_id', 'status'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Review::reviews_all';
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
    protected function isIdempotent(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $reviewId = $this->requireInt($arguments, 'review_id');
        $status = $this->requireString($arguments, 'status');
        if (!isset(ReviewProjector::STATUSES[$status])) {
            throw new LocalizedException(
                __(
                    'The "status" argument must be one of: %1.',
                    implode(', ', array_keys(ReviewProjector::STATUSES))
                )
            );
        }

        $review = $this->locator->get($reviewId);
        $previous = array_search((int) $review->getData('status_id'), ReviewProjector::STATUSES, true) ?: null;

        $review->setData('status_id', ReviewProjector::STATUSES[$status]);
        $this->locator->save($review);
        $review->aggregate();

        return [
            'review_id' => $reviewId,
            'previous_status' => $previous,
            'status' => $status,
        ];
    }
}
