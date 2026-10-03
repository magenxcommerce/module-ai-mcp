<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\QuickSearchGraphQl\Model\PromotionRepository;
use Magento\Framework\Exception\LocalizedException;

/**
 * Change a quick search promotion.
 *
 * Loaded and mutated, so a field not passed keeps its value; the nullable ones
 * — title, image, keywords and the two dates — are cleared by passing null.
 */
class UpdateQuickSearchPromotion extends AbstractTool
{
    /**
     * @param PromotionLocator $locator
     * @param PromotionRepository $repository
     * @param PromotionArguments $arguments
     * @param PromotionProjector $projector
     */
    public function __construct(
        private readonly PromotionLocator $locator,
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
        return 'update_quick_search_promotion';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a quick search promotion: its target, keywords, dates, store view, order, '
            . 'title or image, or switch it off with is_active false. Only the fields you pass '
            . 'change; pass null to clear title, image, keywords or a date. Clearing keywords '
            . 'makes it a general promotion, shown on every search. The result reports, per store '
            . 'view, whether the dropdown will show it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                $this->arguments->schemaProperties()
            ),
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
    protected function isDestructive(): bool
    {
        // Changes the fields passed on one promotion; removes nothing.
        return false;
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
        $promotion = $this->locator->locate($this->requireInt($arguments, 'promotion_id'));

        unset($arguments['promotion_id']);
        $changed = $this->arguments->applyTo($promotion, $arguments, false);
        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides promotion_id.')
            );
        }

        $this->repository->save($promotion);
        $promotion = $this->repository->getById((int) $promotion->getId());

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
        ] + $this->projector->toDetail($promotion);
    }
}
