<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\QuickSearch;

use Magenx\QuickSearchGraphQl\Model\Promotion;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Presents a quick search promotion.
 *
 * The summary is the row plus one derived field, `schedule`, because the date
 * window is the part of a row a reader gets wrong: `active_to` is inclusive and
 * both dates are read in the store view's timezone, not the server's. The
 * detail adds what the target is called and whether the storefront would show
 * it today, which costs lookups per store view and so is never done for a page
 * of results.
 */
class PromotionProjector
{
    public const SCHEDULE_DISABLED = 'disabled';
    public const SCHEDULE_SCHEDULED = 'scheduled';
    public const SCHEDULE_LIVE = 'live';
    public const SCHEDULE_EXPIRED = 'expired';

    /** @var array<int, string> Today's date per store view, for one request. */
    private array $today = [];

    /**
     * @param TimezoneInterface $timezone
     * @param TargetValidator $targetValidator
     * @param StorefrontVisibility $storefrontVisibility
     */
    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly TargetValidator $targetValidator,
        private readonly StorefrontVisibility $storefrontVisibility
    ) {
    }

    /**
     * @param Promotion $promotion
     * @return array<string, mixed>
     */
    public function toSummary(Promotion $promotion): array
    {
        $storeId = (int) $promotion->getData('store_id');
        $keywords = $this->keywords((string) $promotion->getData('keywords'));

        return [
            'promotion_id' => (int) $promotion->getId(),
            'type' => (string) $promotion->getData('type'),
            'target' => (string) $promotion->getData('target'),
            'title' => $promotion->getData('title'),
            'image' => $promotion->getData('image'),
            'keywords' => $keywords,
            // Said outright, because "keywords: []" reads as "matches nothing"
            // when it means the opposite.
            'general' => $keywords === [],
            'store_id' => $storeId,
            'is_active' => (bool) $promotion->getData('is_active'),
            'active_from' => $promotion->getData('active_from') ?: null,
            'active_to' => $promotion->getData('active_to') ?: null,
            'sort_order' => (int) $promotion->getData('sort_order'),
            'schedule' => $this->schedule($promotion, $storeId),
        ];
    }

    /**
     * @param Promotion $promotion
     * @return array<string, mixed>
     */
    public function toDetail(Promotion $promotion): array
    {
        $detail = $this->toSummary($promotion);

        $detail['target_label'] = $this->targetValidator->label($detail['type'], $detail['target']);
        $detail['target_exists'] = $detail['target_label'] !== null;
        $detail['created_at'] = $promotion->getData('created_at');
        $detail['updated_at'] = $promotion->getData('updated_at');
        $detail['storefront'] = $this->storefrontVisibility->check($promotion);

        return $detail;
    }

    /**
     * Where today falls against the promotion's window.
     *
     * For a promotion shown in every store view this is judged in the default
     * scope's timezone. Store views in other timezones can disagree for a few
     * hours either side of midnight; `storefront` in the detail is per store.
     *
     * @param Promotion $promotion
     * @param int $storeId
     * @return string
     */
    private function schedule(Promotion $promotion, int $storeId): string
    {
        if (!(bool) $promotion->getData('is_active')) {
            return self::SCHEDULE_DISABLED;
        }

        $today = $this->today[$storeId] ??= $this->timezone->scopeDate($storeId)->format('Y-m-d');
        $from = (string) $promotion->getData('active_from');
        $to = (string) $promotion->getData('active_to');

        if ($from !== '' && $from > $today) {
            return self::SCHEDULE_SCHEDULED;
        }
        if ($to !== '' && $to < $today) {
            return self::SCHEDULE_EXPIRED;
        }

        return self::SCHEDULE_LIVE;
    }

    /**
     * The keywords as the storefront reads them: split on commas, trimmed,
     * lower-cased, without duplicates.
     *
     * @param string $raw
     * @return string[]
     */
    private function keywords(string $raw): array
    {
        $keywords = [];
        foreach (explode(',', $raw) as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword !== '') {
                // The value, not the key, is returned: a keyword such as
                // "2026" would come back from array_keys() as an integer.
                $keywords[$keyword] = $keyword;
            }
        }

        return array_values($keywords);
    }
}
