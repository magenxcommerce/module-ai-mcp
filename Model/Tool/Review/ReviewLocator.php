<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Review;

use Magento\Framework\Exception\LocalizedException;
use Magento\Review\Model\ResourceModel\Rating\Option\Vote\CollectionFactory as VoteCollectionFactory;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;

/**
 * Loads one review and its votes, or says plainly that there is none.
 *
 * The review module has no repository, so this is the model and its resource,
 * as Magento's own admin controllers use them.
 */
class ReviewLocator
{
    /**
     * @param ReviewFactory $reviewFactory
     * @param ReviewResource $reviewResource
     * @param VoteCollectionFactory $voteCollectionFactory
     */
    public function __construct(
        private readonly ReviewFactory $reviewFactory,
        private readonly ReviewResource $reviewResource,
        private readonly VoteCollectionFactory $voteCollectionFactory
    ) {
    }

    /**
     * @param int $reviewId
     * @return Review
     * @throws LocalizedException
     */
    public function get(int $reviewId): Review
    {
        $review = $this->reviewFactory->create();
        $this->reviewResource->load($review, $reviewId);
        if (!$review->getId()) {
            throw new LocalizedException(__('There is no review with id %1.', $reviewId));
        }

        return $review;
    }

    /**
     * @param Review $review
     * @return void
     */
    public function save(Review $review): void
    {
        $this->reviewResource->save($review);
    }

    /**
     * @param int $reviewId
     * @return iterable<object>
     */
    public function votes(int $reviewId): iterable
    {
        return $this->voteCollectionFactory->create()
            ->setReviewFilter($reviewId)
            ->addRatingInfo();
    }
}
