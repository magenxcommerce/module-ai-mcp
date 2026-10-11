<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Review;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * One review, with its rating votes and the store views it shows on.
 */
class GetReview extends AbstractTool implements PersonalDataToolInterface
{
    /**
     * @param ReviewLocator $locator
     * @param ReviewProjector $projector
     */
    public function __construct(
        private readonly ReviewLocator $locator,
        private readonly ReviewProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_review';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'One product review by id: status, product, title, text, reviewer nickname, customer '
            . 'id when signed in, rating votes and the store views it is shown on.';
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
            ],
            'required' => ['review_id'],
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
    public function execute(array $arguments): array
    {
        $reviewId = $this->requireInt($arguments, 'review_id');
        $review = $this->locator->get($reviewId);

        return $this->projector->toArray($review, $this->projector->votes($this->locator->votes($reviewId)))
            + ['store_ids' => array_map('intval', (array) $review->getData('stores'))];
    }
}
