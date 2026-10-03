<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\PromotionRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns `promotion_id` into the promotion, with a refusal that says where to
 * look instead.
 */
class PromotionLocator
{
    /**
     * @param PromotionRepository $repository
     */
    public function __construct(
        private readonly PromotionRepository $repository
    ) {
    }

    /**
     * @param int $promotionId
     * @return Promotion
     * @throws LocalizedException
     */
    public function locate(int $promotionId): Promotion
    {
        try {
            return $this->repository->getById($promotionId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__(
                'No quick search promotion exists with promotion_id %1. Use '
                . 'search_quick_search_promotions to see them.',
                $promotionId
            ));
        }
    }

    /**
     * Schema fragment for the argument that names a promotion.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'promotion_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'The promotion id, from search_quick_search_promotions.',
            ],
        ];
    }
}
