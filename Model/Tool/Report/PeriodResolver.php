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
        'this_year',
        'last_year',
    ];

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
                'enum' => self::NAMED_PERIODS,
                'description' => 'A named period, resolved against the store\'s own timezone so it '
                    . 'matches what the admin shows. "last_7_days" and "last_30_days" end today, '
                    . 'inclusive. Defaults to "last_30_days". Pass date_from/date_to instead for '
                    . 'anything else.',
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
        if (!in_array($name, self::NAMED_PERIODS, true)) {
            throw new LocalizedException(__(
                'Unknown period "%1". Use one of: %2 — or pass date_from and date_to.',
                $name,
                implode(', ', self::NAMED_PERIODS)
            ));
        }

        $today = $this->today($zone);

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
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $this->endOfYear($today)],
            'last_year' => [
                $today->setDate((int) $today->format('Y') - 1, 1, 1),
                $this->endOfYear($today->setDate((int) $today->format('Y') - 1, 1, 1)),
            ],
        };
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
