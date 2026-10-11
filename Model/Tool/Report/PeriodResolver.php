<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Report;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Turns "last month" into the exact UTC range a query can filter on.
 *
 * Every other date argument in this server is UTC, because that is how Magento
 * stores one. Reporting is the exception: a merchant asking about "yesterday"
 * means their own calendar, and a daily figure computed in UTC disagrees with
 * the one the admin shows them by however many hours their store is offset.
 * So the periods here are resolved against the store's configured timezone, and
 * the resulting {@see Period} carries both readings.
 *
 * The conversion is done with `DateTimeZone` rather than a fixed offset, so a
 * period spanning a daylight-saving change still resolves to the correct UTC
 * instants and its total is exactly right. Bucketing is the looser half — see
 * {@see Period::getOffsetForSql()}.
 */
class PeriodResolver
{
    public const GRANULARITY_NONE = 'none';
    public const GRANULARITY_DAY = 'day';
    public const GRANULARITY_WEEK = 'week';
    public const GRANULARITY_MONTH = 'month';

    /** The named periods, in the order they are offered to an agent. */
    private const NAMED_PERIODS = [
        'today',
        'yesterday',
        'last_7_days',
        'last_30_days',
        'this_month',
        'last_month',
        'this_quarter',
        'last_quarter',
        'this_year',
        'last_year',
        'mtd',
        'qtd',
        'ytd',
    ];

    /**
     * "last_18_months" and its kin: a rolling window of N units ending today.
     *
     * Rolling rather than whole calendar units because that is what the
     * existing last_7_days and last_30_days already mean, and one word should
     * not mean two things. Whole months are last_month, or date_from/date_to.
     */
    private const RELATIVE_PATTERN = '/^last_([1-9][0-9]{0,3})_(days|weeks|months|years)$/';

    /**
     * Longest window each unit may name — ten years either way. Not a cost
     * limit: a number past this is far more likely a typo than a question.
     */
    private const RELATIVE_LIMITS = ['days' => 3660, 'weeks' => 522, 'months' => 120, 'years' => 10];

    /** compare_to: the window of equal length immediately before. */
    public const COMPARE_PREVIOUS_PERIOD = 'previous_period';

    /** compare_to: the same window one year earlier. */
    public const COMPARE_PREVIOUS_YEAR = 'previous_year';

    /**
     * Most buckets a single answer may contain.
     *
     * Not a performance limit — the query is one GROUP BY either way. It is a
     * context limit: 365 rows of numbers is not an answer a model can reason
     * about, and a year asked for by day is nearly always a month or a week
     * that was meant.
     */
    private const MAX_BUCKETS = 200;

    /** Days per bucket, for estimating the bucket count before querying. */
    private const BUCKET_DAYS = [
        self::GRANULARITY_DAY => 1,
        self::GRANULARITY_WEEK => 7,
        self::GRANULARITY_MONTH => 28,
    ];

    /**
     * @param TimezoneInterface $timezone
     * @param CoreDate $date
     */
    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly CoreDate $date
    ) {
    }

    /**
     * The period arguments every report tool takes.
     *
     * @param bool $withGranularity
     * @return array<string, mixed>
     */
    public function schemaProperties(bool $withGranularity = false): array
    {
        $properties = [
            'period' => [
                'type' => 'string',
                'pattern' => '^(' . implode('|', self::NAMED_PERIODS)
                    . '|last_[1-9][0-9]{0,3}_(days|weeks|months|years))$',
                'examples' => ['last_30_days', 'last_month', 'ytd', 'last_quarter', 'last_18_months'],
                'description' => 'A named period, resolved in the store\'s own timezone: '
                    . implode(', ', self::NAMED_PERIODS) . ', or last_<n>_days|weeks|months|years '
                    . '(a rolling window ending today, e.g. last_18_months). mtd, qtd and ytd end '
                    . 'today; this_quarter and this_year run to their end. Defaults to "last_30_days". '
                    . 'Pass date_from/date_to instead for anything else.',
            ],
            'date_from' => [
                'type' => 'string',
                'description' => 'Start of an explicit range, inclusive: "2026-01-01" or '
                    . '"2026-01-01 09:30:00". Read in the store\'s timezone, not UTC — unlike the '
                    . 'date arguments on search_orders. Requires date_to and excludes period.',
            ],
            'date_to' => [
                'type' => 'string',
                'description' => 'End of an explicit range, inclusive. A bare date means the end of '
                    . 'that day, so "2026-01-31" includes everything on the 31st.',
            ],
        ];

        if ($withGranularity) {
            $properties['granularity'] = [
                'type' => 'string',
                'enum' => [self::GRANULARITY_DAY, self::GRANULARITY_WEEK, self::GRANULARITY_MONTH],
                'description' => 'Bucket size. Defaults to "day". At most '
                    . self::MAX_BUCKETS . ' buckets are returned; a longer range needs a coarser '
                    . 'granularity.',
            ];
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param int|null $storeId Scope whose timezone applies; null for the default.
     * @param bool $withGranularity
     * @return Period
     * @throws LocalizedException
     */
    public function resolve(array $arguments, ?int $storeId = null, bool $withGranularity = false): Period
    {
        $zone = $this->zone($storeId);
        $granularity = $withGranularity
            ? $this->granularity($arguments)
            : self::GRANULARITY_NONE;

        [$from, $to] = $this->range($arguments, $zone);

        if ($from > $to) {
            throw new LocalizedException(
                __('date_from (%1) is after date_to (%2).', $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'))
            );
        }

        $this->assertBucketsAreReadable($from, $to, $granularity);

        return $this->build($from, $to, $zone, $granularity);
    }

    /**
     * The compare_to argument, for the tools that offer a comparison.
     *
     * @return array<string, mixed>
     */
    public function comparisonSchemaProperties(): array
    {
        return [
            'compare_to' => [
                'type' => 'string',
                'enum' => [self::COMPARE_PREVIOUS_PERIOD, self::COMPARE_PREVIOUS_YEAR],
                'description' => 'Also report a second period to compare against: "previous_period" '
                    . '(the same length immediately before; whole months shift by whole months) or '
                    . '"previous_year" (the same dates a year earlier). Omit for no comparison.',
            ],
        ];
    }

    /**
     * The compare_to argument, validated, or null when none was asked for.
     *
     * @param array<string, mixed> $arguments
     * @return string|null
     * @throws LocalizedException
     */
    public function compareTo(array $arguments): ?string
    {
        $value = $arguments['compare_to'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!in_array($value, [self::COMPARE_PREVIOUS_PERIOD, self::COMPARE_PREVIOUS_YEAR], true)) {
            throw new LocalizedException(
                __('The "compare_to" argument must be "previous_period" or "previous_year".')
            );
        }

        return $value;
    }

    /**
     * The period a resolved one is compared against.
     *
     * Three shapes, because "the period before" means different things for
     * each. A range of whole calendar months — last_month, a quarter, a year —
     * shifts by whole months, so February is compared with January and not
     * with the 28 days before it. A range of whole days shifts by that many
     * days. Anything cut at a time of day shifts by its exact length.
     *
     * A year back keeps month ends as month ends, so February 2025 compares
     * with February 2024 including its 29th.
     *
     * @param Period $period
     * @param string $compareTo One of the COMPARE_* constants.
     * @return Period
     */
    public function comparison(Period $period, string $compareTo): Period
    {
        $zone = new \DateTimeZone($period->getTimezone());
        $from = $this->fromLocal($period->getLocalFrom(), $zone);
        $to = $this->fromLocal($period->getLocalTo(), $zone);
        $months = $this->wholeMonths($from, $to);

        if ($compareTo === self::COMPARE_PREVIOUS_YEAR) {
            $lastMonth = $to->modify('first day of this month')->modify('-12 months');
            [$from, $to] = $months !== null
                ? [$from->modify('-12 months'), $this->endOfMonth($lastMonth)]
                : [$from->modify('-1 year'), $to->modify('-1 year')];
        } elseif ($months !== null) {
            [$from, $to] = [
                $from->modify(sprintf('-%d months', $months)),
                $this->endOfMonth($from->modify('-1 month')),
            ];
        } elseif ($this->isWholeDays($from, $to)) {
            $days = (int) $from->diff($to->setTime(0, 0))->days + 1;
            [$from, $to] = [$from->modify(sprintf('-%d days', $days)), $this->endOfDay($from->modify('-1 day'))];
        } else {
            $length = $to->getTimestamp() - $from->getTimestamp();
            $to = $from->modify('-1 second');
            $from = $to->modify(sprintf('-%d seconds', $length));
        }

        return $this->build($from, $to, $zone, $period->getGranularity());
    }

    /**
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @param \DateTimeZone $zone
     * @param string $granularity
     * @return Period
     */
    private function build(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $zone,
        string $granularity
    ): Period {
        $utc = new \DateTimeZone('UTC');

        return new Period(
            $zone->getName(),
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            $granularity,
            $from->getOffset()
        );
    }

    /**
     * @param string $local
     * @param \DateTimeZone $zone
     * @return \DateTimeImmutable
     */
    private function fromLocal(string $local, \DateTimeZone $zone): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, $zone);
        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('Not a resolved local time: "%s".', $local));
        }

        return $parsed;
    }

    /**
     * How many calendar months the range covers, when it is exactly whole
     * months; otherwise null.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return int|null
     */
    private function wholeMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): ?int
    {
        if ($from->format('d H:i:s') !== '01 00:00:00'
            || $to->format('H:i:s') !== '23:59:59'
            || $to->format('d') !== $to->format('t')
        ) {
            return null;
        }

        return ((int) $to->format('Y') * 12 + (int) $to->format('n'))
            - ((int) $from->format('Y') * 12 + (int) $from->format('n')) + 1;
    }

    /**
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @return bool
     */
    private function isWholeDays(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $from->format('H:i:s') === '00:00:00' && $to->format('H:i:s') === '23:59:59';
    }

    /**
     * @param int|null $storeId
     * @return \DateTimeZone
     * @throws LocalizedException
     */
    private function zone(?int $storeId): \DateTimeZone
    {
        $name = (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $storeId);

        try {
            return new \DateTimeZone($name === '' ? 'UTC' : $name);
        } catch (\Exception) {
            // A timezone Magento accepted but PHP will not is a configuration
            // fault, and silently reporting in UTC instead would put a wrong
            // day on every figure without saying so.
            throw new LocalizedException(
                __('The store is configured with a timezone PHP does not recognise: "%1".', $name)
            );
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function granularity(array $arguments): string
    {
        $value = $arguments['granularity'] ?? null;
        if ($value === null || $value === '') {
            return self::GRANULARITY_DAY;
        }

        if (!is_string($value) || !isset(self::BUCKET_DAYS[$value])) {
            throw new LocalizedException(__(
                'The "granularity" argument must be one of: %1.',
                implode(', ', array_keys(self::BUCKET_DAYS))
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param \DateTimeZone $zone
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     * @throws LocalizedException
     */
    private function range(array $arguments, \DateTimeZone $zone): array
    {
        $from = $this->optionalDateArgument($arguments, 'date_from');
        $to = $this->optionalDateArgument($arguments, 'date_to');
        $named = $arguments['period'] ?? null;

        if (($from !== null || $to !== null) && $named !== null && $named !== '') {
            throw new LocalizedException(__(
                'Pass either "period" or the date_from/date_to pair, not both — they describe the '
                . 'same thing two ways.'
            ));
        }

        if ($from !== null || $to !== null) {
            if ($from === null || $to === null) {
                throw new LocalizedException(
                    __('date_from and date_to go together; pass both, or a named "period" instead.')
                );
            }

            return [$this->startOfDayOrTime($from, $zone), $this->endOfDayOrTime($to, $zone)];
        }

        return $this->namedRange(is_string($named) && $named !== '' ? $named : 'last_30_days', $zone);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return string|null
     * @throws LocalizedException
     */
    private function optionalDateArgument(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new LocalizedException(__('The "%1" argument must be a date string.', $key));
        }

        return trim($value);
    }

    /**
     * @param string $name
     * @param \DateTimeZone $zone
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     * @throws LocalizedException
     */
    private function namedRange(string $name, \DateTimeZone $zone): array
    {
        if (preg_match(self::RELATIVE_PATTERN, $name, $match) === 1) {
            return $this->relativeRange((int) $match[1], $match[2], $zone);
        }

        if (!in_array($name, self::NAMED_PERIODS, true)) {
            throw new LocalizedException(__(
                'Unknown period "%1". Use one of: %2, last_<n>_days|weeks|months|years — or pass '
                . 'date_from and date_to.',
                $name,
                implode(', ', self::NAMED_PERIODS)
            ));
        }

        $today = $this->today($zone);
        $quarter = $this->startOfQuarter($today);

        return match ($name) {
            'today' => [$today, $this->endOfDay($today)],
            'yesterday' => [$today->modify('-1 day'), $this->endOfDay($today->modify('-1 day'))],
            'last_7_days' => [$today->modify('-6 days'), $this->endOfDay($today)],
            'last_30_days' => [$today->modify('-29 days'), $this->endOfDay($today)],
            'this_month' => [$today->modify('first day of this month'), $this->endOfMonth($today)],
            'last_month' => [
                $today->modify('first day of last month'),
                $this->endOfMonth($today->modify('first day of last month')),
            ],
            'this_quarter' => [$quarter, $this->endOfMonth($quarter->modify('+2 months'))],
            'last_quarter' => [$quarter->modify('-3 months'), $this->endOfMonth($quarter->modify('-1 month'))],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $this->endOfYear($today)],
            'last_year' => [
                $today->setDate((int) $today->format('Y') - 1, 1, 1),
                $this->endOfYear($today->setDate((int) $today->format('Y') - 1, 1, 1)),
            ],
            'mtd' => [$today->modify('first day of this month'), $this->endOfDay($today)],
            'qtd' => [$quarter, $this->endOfDay($today)],
            'ytd' => [$today->setDate((int) $today->format('Y'), 1, 1), $this->endOfDay($today)],
        };
    }

    /**
     * A rolling window of $count units ending today, inclusive.
     *
     * Months are counted on the calendar rather than as 30 days, and a day of
     * the month that the earlier month lacks is clamped to its last day, so
     * last_1_months on 31 March starts on 1 March rather than overflowing into
     * it from a February 31st.
     *
     * @param int $count
     * @param string $unit
     * @param \DateTimeZone $zone
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     * @throws LocalizedException
     */
    private function relativeRange(int $count, string $unit, \DateTimeZone $zone): array
    {
        if ($count > self::RELATIVE_LIMITS[$unit]) {
            throw new LocalizedException(__(
                'last_%1_%2 is longer than this server reports over; the most is %3 %2.',
                $count,
                $unit,
                self::RELATIVE_LIMITS[$unit]
            ));
        }

        $today = $this->today($zone);
        $end = $this->endOfDay($today);

        if ($unit === 'days' || $unit === 'weeks') {
            $days = $unit === 'weeks' ? $count * 7 : $count;

            return [$today->modify(sprintf('-%d days', $days - 1)), $end];
        }

        $months = $unit === 'years' ? $count * 12 : $count;
        $target = $today->modify('first day of this month')->modify(sprintf('-%d months', $months));
        $day = min((int) $today->format('j'), (int) $target->format('t'));

        $start = $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);

        return [$start->modify('+1 day'), $end];
    }

    /**
     * Midnight on the first day of the quarter $day falls in.
     *
     * @param \DateTimeImmutable $day
     * @return \DateTimeImmutable
     */
    private function startOfQuarter(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $month = intdiv((int) $day->format('n') - 1, 3) * 3 + 1;

        return $day->setDate((int) $day->format('Y'), $month, 1);
    }

    /**
     * Midnight today, in the store's calendar.
     *
     * Derived from a UTC timestamp rather than `new DateTimeImmutable('now')`
     * so the clock is the one Magento uses — and so a test can fix it.
     *
     * @param \DateTimeZone $zone
     * @return \DateTimeImmutable
     */
    private function today(\DateTimeZone $zone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->date->gmtTimestamp()))
            ->setTimezone($zone)
            ->setTime(0, 0);
    }

    /**
     * @param \DateTimeImmutable $day
     * @return \DateTimeImmutable
     */
    private function endOfDay(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(23, 59, 59);
    }

    /**
     * @param \DateTimeImmutable $day
     * @return \DateTimeImmutable
     */
    private function endOfMonth(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $this->endOfDay($day->modify('last day of this month'));
    }

    /**
     * @param \DateTimeImmutable $day
     * @return \DateTimeImmutable
     */
    private function endOfYear(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $this->endOfDay($day->setDate((int) $day->format('Y'), 12, 31));
    }

    /**
     * A bare "2026-01-01" means the start of that day.
     *
     * @param string $value
     * @param \DateTimeZone $zone
     * @return \DateTimeImmutable
     * @throws LocalizedException
     */
    private function startOfDayOrTime(string $value, \DateTimeZone $zone): \DateTimeImmutable
    {
        $parsed = $this->parse($value, $zone, 'date_from');

        return $this->hasTime($value) ? $parsed : $parsed->setTime(0, 0);
    }

    /**
     * A bare "2026-01-31" means the *end* of that day, so a caller asking for a
     * month gets the whole month rather than losing its last 24 hours.
     *
     * @param string $value
     * @param \DateTimeZone $zone
     * @return \DateTimeImmutable
     * @throws LocalizedException
     */
    private function endOfDayOrTime(string $value, \DateTimeZone $zone): \DateTimeImmutable
    {
        $parsed = $this->parse($value, $zone, 'date_to');

        return $this->hasTime($value) ? $parsed : $this->endOfDay($parsed);
    }

    /**
     * @param string $value
     * @return bool
     */
    private function hasTime(string $value): bool
    {
        return str_contains($value, ':');
    }

    /**
     * @param string $value
     * @param \DateTimeZone $zone
     * @param string $argument
     * @return \DateTimeImmutable
     * @throws LocalizedException
     */
    private function parse(string $value, \DateTimeZone $zone, string $argument): \DateTimeImmutable
    {
        // Deliberately not strtotime(): it accepts "next tuesday" and "+1 week"
        // and would answer a question nobody asked. A report has to be
        // reproducible from its arguments.
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        throw new LocalizedException(__(
            'The "%1" argument must be "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS". Got "%2".',
            $argument,
            $value
        ));
    }

    /**
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @param string $granularity
     * @return void
     * @throws LocalizedException
     */
    private function assertBucketsAreReadable(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity
    ): void {
        if (!isset(self::BUCKET_DAYS[$granularity])) {
            return;
        }

        $days = (int) $from->diff($to)->days + 1;
        $buckets = (int) ceil($days / self::BUCKET_DAYS[$granularity]);

        if ($buckets > self::MAX_BUCKETS) {
            throw new LocalizedException(__(
                'That range is %1 %2 buckets, over the limit of %3. Use a coarser granularity or a '
                . 'shorter range.',
                $buckets,
                $granularity,
                self::MAX_BUCKETS
            ));
        }
    }
}
