<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Read one quick search promotion, and whether the storefront shows it.
 */
class GetQuickSearchPromotion extends AbstractTool
{
    /**
     * @param PromotionLocator $locator
     * @param PromotionProjector $projector
     */
    public function __construct(
        private readonly PromotionLocator $locator,
        private readonly PromotionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_quick_search_promotion';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one quick search promotion, what its target is called, and — per store view '
            . 'it applies to — whether the dropdown shows it today and, if not, why: promotions '
            . 'switched off, disabled, outside its dates, past the Maximum Items cap, or a target '
            . 'the storefront drops (a disabled, invisible or out-of-stock product, an inactive '
            . 'category). Keyword matching is not checked; it happens in the browser.';
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
    public function execute(array $arguments): array
    {
        $promotion = $this->locator->locate($this->requireInt($arguments, 'promotion_id'));

        return $this->projector->toDetail($promotion);
    }
}
