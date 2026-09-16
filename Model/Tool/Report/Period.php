<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

/**
 * One resolved reporting period: what was asked for, and what will be queried.
 *
 * Both halves matter. A merchant asks about their own calendar — "last month",
 * "yesterday" — but `sales_order.created_at` is UTC, and so is every other date
 * argument in this server. Carrying both means a report can say which days it
 * covered *and* hand back the exact UTC range a follow-up `search_orders` call
 * needs to see the same orders.
 */
class Period
{
    /**
     * @param string $timezone IANA name of the store's configured timezone.
     * @param string $localFrom Local start, inclusive, "Y-m-d H:i:s".
     * @param string $localTo Local end, inclusive.
     * @param string $utcFrom UTC start, inclusive — what the query filters on.
     * @param string $utcTo UTC end, inclusive.
     * @param string $granularity One of day, week, month, or none for a single total.
     * @param int $offsetSeconds UTC offset in effect at the start of the period.
     */
    public function __construct(
        private readonly string $timezone,
        private readonly string $localFrom,
        private readonly string $localTo,
        private readonly string $utcFrom,
        private readonly string $utcTo,
        private readonly string $granularity,
        private readonly int $offsetSeconds
    ) {
    }

    /**
     * @return string
     */
    public function getUtcFrom(): string
    {
        return $this->utcFrom;
    }

    /**
     * @return string
     */
    public function getUtcTo(): string
    {
        return $this->utcTo;
    }

    /**
     * @return string
     */
    public function getGranularity(): string
    {
        return $this->granularity;
    }

    /**
     * The offset as MySQL's CONVERT_TZ wants it, e.g. "+01:00".
     *
     * A numeric offset rather than the IANA name on purpose: CONVERT_TZ only
     * understands named zones when the server's timezone tables have been
     * loaded, which on a stock MySQL they have not, and it returns NULL rather
     * than failing when they are missing — every bucket would silently collapse
     * into one.
     *
     * @return string
     */
    public function getOffsetForSql(): string
    {
        $sign = $this->offsetSeconds < 0 ? '-' : '+';
        $absolute = abs($this->offsetSeconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($absolute, 3600), intdiv($absolute % 3600, 60));
    }

    /**
     * What to report back beside the figures, so the caller can see which
     * calendar the answer is on and reproduce it against the UTC-based tools.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'from' => $this->localFrom,
            'to' => $this->localTo,
            'utc_from' => $this->utcFrom,
            'utc_to' => $this->utcTo,
            'granularity' => $this->granularity,
        ];
    }
}
