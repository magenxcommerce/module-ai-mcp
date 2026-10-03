<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\QuickSearchGraphQl\Model\PromotionRepository;

/**
 * Delete a quick search promotion.
 *
 * The result carries the row as it was, so the promotion can be recreated from
 * it — the only undo there is.
 */
class DeleteQuickSearchPromotion extends AbstractTool
{
    /**
     * @param PromotionLocator $locator
     * @param PromotionRepository $repository
     * @param PromotionProjector $projector
     */
    public function __construct(
        private readonly PromotionLocator $locator,
        private readonly PromotionRepository $repository,
        private readonly PromotionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_quick_search_promotion';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a quick search promotion. This cannot be undone; the result returns the '
            . 'deleted row so it can be recreated. To take one off the storefront but keep it, '
            . 'set is_active false or an active_to date with update_quick_search_promotion '
            . 'instead.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->locator->schemaProperties(),
            'required' => ['promotion_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_QuickSearchGraphQl::promotion';
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
        $promotion = $this->locator->locate($this->requireInt($arguments, 'promotion_id'));
        $deleted = $this->projector->toSummary($promotion);

        $this->repository->delete($promotion);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'promotion' => $deleted,
        ];
    }
}
