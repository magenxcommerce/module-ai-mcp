<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\QuickSearchGraphQl\Model\PromotionFactory;
use Magenx\QuickSearchGraphQl\Model\PromotionRepository;

/**
 * Add a sponsored item to the quick-search dropdown.
 *
 * Saved through the module's repository, so the model's cache tag is cleaned
 * and every store's cached pool is rebuilt on the next dropdown open — the
 * promotion is live as soon as this returns, if its dates say so.
 */
class CreateQuickSearchPromotion extends AbstractTool
{
    /**
     * @param PromotionFactory $promotionFactory
     * @param PromotionRepository $repository
     * @param PromotionArguments $arguments
     * @param PromotionProjector $projector
     */
    public function __construct(
        private readonly PromotionFactory $promotionFactory,
        private readonly PromotionRepository $repository,
        private readonly PromotionArguments $arguments,
        private readonly PromotionProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_quick_search_promotion';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a sponsored product, category or brand to the storefront\'s quick-search '
            . 'dropdown. With no keywords it is a general promotion, shown when the search box '
            . 'opens empty and after every search; with keywords it shows below the results of '
            . 'matching searches. The target must exist. Live immediately unless dates or '
            . 'is_active say otherwise; the result reports, per store view, whether the dropdown '
            . 'will actually show it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->arguments->schemaProperties(),
            'required' => ['type', 'target'],
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
    protected function isDestructive(): bool
    {
        // Adds a row. It can push another promotion past the Maximum Items cap,
        // but that one is unchanged and comes back when this one goes.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $promotion = $this->promotionFactory->create();
        $this->arguments->applyTo($promotion, $arguments, true);
        $this->repository->save($promotion);

        // Reloaded so the result carries what the table holds, timestamps
        // included, rather than what was set on the object.
        $promotion = $this->repository->getById((int) $promotion->getId());

        return [
            'created' => true,
            'tool' => $this->getName(),
        ] + $this->projector->toDetail($promotion);
    }
}
