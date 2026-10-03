<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\QuickSearchGraphQl\Model\Config;
use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magenx\QuickSearchGraphQl\Model\PromotionProvider;
use Magenx\QuickSearchGraphQl\Model\Source\Type;
use Magenx\QuickSearchGraphQl\Model\TargetLoader;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Whether the storefront's dropdown would show a promotion today, per store
 * view, and if not, the first reason why.
 *
 * "Why is my promotion not showing" is the question that brings anybody to
 * these tools, and a saved row answers almost none of it. Four things stand
 * between a row and the dropdown, and this asks the quick search module's own
 * code about each, so the answer cannot drift from what `quickSearchSuggestions`
 * actually does:
 *
 *  1. Promotions are switched on for the store view.
 *  2. The row is enabled and today, in that store's timezone, is inside its
 *     date window — {@see PromotionProvider::getActive()}.
 *  3. It is within the first **Maximum Items** of that pool. The cap is applied
 *     to the pool *before* targets are resolved, so a promotion past it is cut
 *     even if an earlier one is then dropped for a dead target.
 *  4. Its target resolves the way the resolver resolves it: an enabled, visible,
 *     in-stock product in the store's website; an active category under the
 *     store's root; a brand option that still exists — {@see TargetLoader}.
 *
 * Keyword matching is not checked: it happens in the browser, against whatever
 * the shopper types.
 *
 * A promotion for all store views (`store_id` 0) is checked in every store
 * view, because each has its own config, date and catalogue. That is one cached
 * pool read and one target lookup per store view, which is why this runs for
 * one promotion at a time and never on a search page.
 */
class StorefrontVisibility
{
    /** Effectively "no cap", to find a row's rank in the uncapped pool. */
    private const WHOLE_POOL = PHP_INT_MAX;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param PromotionProvider $promotionProvider
     * @param TargetLoader $targetLoader
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly PromotionProvider $promotionProvider,
        private readonly TargetLoader $targetLoader
    ) {
    }

    /**
     * One entry per store view the promotion is meant for.
     *
     * @param Promotion $promotion
     * @return array<int, array<string, mixed>>
     */
    public function check(Promotion $promotion): array
    {
        $result = [];
        foreach ($this->storesFor((int) $promotion->getData('store_id')) as $store) {
            $reason = $this->reasonHidden($promotion, $store);
            $result[] = [
                'store_id' => (int) $store->getId(),
                'store_code' => (string) $store->getCode(),
                'shown' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    /**
     * @param int $storeId
     * @return Store[]
     */
    private function storesFor(int $storeId): array
    {
        if ($storeId === 0) {
            return array_values($this->storeManager->getStores());
        }

        try {
            return [$this->storeManager->getStore($storeId)];
        } catch (NoSuchEntityException) {
            // The store view is gone; the foreign key will have removed the row
            // with it, so there is nothing left to check.
            return [];
        }
    }

    /**
     * The first reason the dropdown would not show this promotion, or null.
     *
     * @param Promotion $promotion
     * @param Store $store
     * @return string|null
     */
    private function reasonHidden(Promotion $promotion, Store $store): ?string
    {
        $storeId = (int) $store->getId();

        if (!$this->config->isPromotionsEnabled($storeId)) {
            return 'Promotions are switched off for this store view '
                . '(magenx_quick_search/promotions/enabled).';
        }

        if (!(bool) $promotion->getData('is_active')) {
            return 'The promotion is disabled (is_active false).';
        }

        $rank = $this->rankInPool((int) $promotion->getId(), $storeId);
        if ($rank === null) {
            return 'Today, in this store view\'s timezone, is outside the promotion\'s '
                . 'active_from / active_to window.';
        }

        $maxItems = $this->config->getPromotionsMaxItems($storeId);
        if ($rank >= $maxItems) {
            return sprintf(
                'It is number %d in this store view\'s pool of live promotions, past the '
                . 'Maximum Items cap of %d (magenx_quick_search/promotions/max_items). Lower its '
                . 'sort_order, or raise the cap.',
                $rank + 1,
                $maxItems
            );
        }

        if (!$this->targetResolves($promotion, $store)) {
            return $this->targetReason((string) $promotion->getData('type'));
        }

        return null;
    }

    /**
     * Zero-based position of the promotion in the store's live pool, or null
     * when it is not in the pool at all.
     *
     * @param int $promotionId
     * @param int $storeId
     * @return int|null
     */
    private function rankInPool(int $promotionId, int $storeId): ?int
    {
        foreach ($this->promotionProvider->getActive($storeId, self::WHOLE_POOL) as $rank => $row) {
            if ((int) ($row['promotion_id'] ?? 0) === $promotionId) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * @param Promotion $promotion
     * @param Store $store
     * @return bool
     */
    private function targetResolves(Promotion $promotion, Store $store): bool
    {
        $storeId = (int) $store->getId();
        $target = (string) $promotion->getData('target');

        return match ((string) $promotion->getData('type')) {
            Type::PRODUCT => $this->targetLoader->loadProducts([$target], $storeId) !== [],
            Type::CATEGORY => $this->targetLoader->loadCategories(
                [$target],
                $storeId,
                (int) $store->getRootCategoryId()
            ) !== [],
            Type::BRAND => $this->targetLoader->loadBrands([$target], $storeId) !== [],
            default => false,
        };
    }

    /**
     * @param string $type
     * @return string
     */
    private function targetReason(string $type): string
    {
        return match ($type) {
            Type::PRODUCT => 'The product is disabled, not visible in catalog or search, out of '
                . 'stock, or not assigned to this store view\'s website.',
            Type::CATEGORY => 'The category is inactive, or is not under this store view\'s root '
                . 'category.',
            Type::BRAND => 'The brand option no longer exists.',
            default => 'The promotion type is not one the storefront knows.',
        };
    }
}
