<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Review;

/**
 * One product review as the review tools return it.
 *
 * Status is reported by name as well as id, because the ids are Magento's own
 * table keys and "2" means nothing to a reader. Ratings are the individual
 * votes — one per rating a store defines, such as Quality or Price — each on
 * Magento's 1–5 scale.
 */
class ReviewProjector
{
    /** Magento's review_status ids, by the name the tools use. */
    public const STATUSES = ['approved' => 1, 'pending' => 2, 'not_approved' => 3];

    /** Longest review text returned verbatim; a review is customer prose. */
    private const MAX_DETAIL_LENGTH = 2000;

    /**
     * @param object $review A \Magento\Review\Model\Review.
     * @param array<int, array<string, mixed>> $ratings
     * @return array<string, mixed>
     */
    public function toArray(object $review, array $ratings): array
    {
        $statusId = (int) $review->getData('status_id');
        $detail = (string) $review->getData('detail');

        return [
            'review_id' => (int) $review->getId(),
            'status' => array_search($statusId, self::STATUSES, true) ?: null,
            'status_id' => $statusId,
            'product_id' => (int) $review->getData('entity_pk_value'),
            'title' => $review->getData('title'),
            'detail' => mb_strlen($detail) > self::MAX_DETAIL_LENGTH
                ? mb_substr($detail, 0, self::MAX_DETAIL_LENGTH) . '…'
                : $detail,
            'nickname' => $review->getData('nickname'),
            'customer_id' => $review->getData('customer_id') === null ? null : (int) $review->getData('customer_id'),
            'store_id' => $review->getData('store_id') === null ? null : (int) $review->getData('store_id'),
            'created_at' => $review->getData('created_at'),
            'ratings' => $ratings,
        ];
    }

    /**
     * The votes of one review, from a vote collection.
     *
     * @param iterable<object> $votes
     * @return array<int, array<string, mixed>>
     */
    public function votes(iterable $votes): array
    {
        $ratings = [];
        foreach ($votes as $vote) {
            $ratings[] = [
                'rating' => $vote->getData('rating_code'),
                'value' => (int) $vote->getData('value'),
                'percent' => (int) $vote->getData('percent'),
            ];
        }

        return $ratings;
    }
}
