<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Report;

use Magenx\AiMcp\Model\Tool\Report\PeriodResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * Resolving "last month" into the UTC range a query can filter on.
 *
 * This is the only place in the server that reads a date in anything but UTC,
 * and it is where a reporting bug would be invisible: every figure downstream
 * is correct arithmetic over the wrong set of orders, so the answer looks
 * plausible and is off by a day's trading. The cases below pin the boundaries
 * that shift — month ends, year ends, the inclusive end of a bare date, and a
 * period spanning a daylight-saving change.
 *
 * The clock is fixed at 2026-03-15 12:00:00 UTC throughout.
 *
 * @see PeriodResolver::resolve
 */
class PeriodResolverTest extends TestCase
{
    /** 2026-03-15 12:00:00 UTC — a Sunday, mid-month, mid-year. */
    private const NOW = 1773576000;

    /**
     * @return void
     */
    public function testAPeriodIsResolvedInTheStoresCalendarNotUtc(): void
    {
        // 12:00 UTC on the 15th is 21:00 on the 15th in Tokyo, so "today"
        // begins at 15:00 UTC on the 14th.
        $period = $this->resolver('Asia/Tokyo')->resolve(['period' => 'today']);

        $this->assertSame('2026-03-15 00:00:00', $period->toArray()['from']);
        $this->assertSame('2026-03-14 15:00:00', $period->getUtcFrom());
        $this->assertSame('Asia/Tokyo', $period->toArray()['timezone']);
    }

    /**
     * @return void
     */
    public function testYesterdayIsAWholeDayNotTheLastTwentyFourHours(): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => 'yesterday']);

        $this->assertSame('2026-03-14 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-03-14 23:59:59', $period->getUtcTo());
    }

    /**
     * Seven days ending today, not seven days before today — the off-by-one
     * that makes a weekly figure quietly exclude the day being asked about.
     *
     * @return void
     */
    public function testLastSevenDaysEndsTodayAndCountsSevenOfThem(): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => 'last_7_days']);

        $this->assertSame('2026-03-09 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-03-15 23:59:59', $period->getUtcTo());
    }

    /**
     * @return void
     */
    public function testThisMonthRunsToTheLastDayOfTheMonth(): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => 'this_month']);

        $this->assertSame('2026-03-01 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-03-31 23:59:59', $period->getUtcTo());
    }

    /**
     * February is where a "last month" that just subtracts 30 days goes wrong.
     *
     * @return void
     */
    public function testLastMonthGetsTheRightNumberOfDays(): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => 'last_month']);

        $this->assertSame('2026-02-01 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-02-28 23:59:59', $period->getUtcTo());
    }

    /**
     * @return void
     */
    public function testLastYearIsTheWholeOfThePreviousYear(): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => 'last_year']);

        $this->assertSame('2025-01-01 00:00:00', $period->getUtcFrom());
        $this->assertSame('2025-12-31 23:59:59', $period->getUtcTo());
    }

    /**
     * A bare date_to means the END of that day. Treating it as midnight would
     * silently drop the last day's trading from every explicit range.
     *
     * @return void
     */
    public function testABareEndDateIncludesTheWholeOfThatDay(): void
    {
        $period = $this->resolver('UTC')->resolve(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertSame('2026-01-01 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-01-31 23:59:59', $period->getUtcTo());
    }

    /**
     * @return void
     */
    public function testAnExplicitTimeIsTakenLiterally(): void
    {
        $period = $this->resolver('UTC')->resolve([
            'date_from' => '2026-01-01 09:30:00',
            'date_to' => '2026-01-01 17:00:00',
        ]);

        $this->assertSame('2026-01-01 09:30:00', $period->getUtcFrom());
        $this->assertSame('2026-01-01 17:00:00', $period->getUtcTo());
    }

    /**
     * Europe/Berlin moves to +02:00 on 2026-03-29. A month containing that
     * change must still start and end at the right UTC instants — an offset
     * applied flat across the range would be an hour out at one end.
     *
     * @return void
     */
    public function testARangeSpanningADaylightSavingChangeResolvesExactly(): void
    {
        $period = $this->resolver('Europe/Berlin')
            ->resolve(['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);

        // 1 March is +01:00; 31 March is +02:00.
        $this->assertSame('2026-02-28 23:00:00', $period->getUtcFrom());
        $this->assertSame('2026-03-31 21:59:59', $period->getUtcTo());
    }

    /**
     * The offset handed to CONVERT_TZ is the one in effect when the period
     * starts, and it has to be formatted the way MySQL expects.
     *
     * @return void
     */
    public function testTheSqlOffsetIsFormattedForConvertTz(): void
    {
        $this->assertSame(
            '+01:00',
            $this->resolver('Europe/Berlin')->resolve(['period' => 'last_month'])->getOffsetForSql()
        );
        $this->assertSame(
            '-05:00',
            $this->resolver('America/New_York')->resolve(['period' => 'last_month'])->getOffsetForSql()
        );
        $this->assertSame(
            '+05:30',
            $this->resolver('Asia/Kolkata')->resolve(['period' => 'last_month'])->getOffsetForSql()
        );
    }

    /**
     * @return void
     */
    public function testTheDefaultPeriodIsTheLastThirtyDays(): void
    {
        $period = $this->resolver('UTC')->resolve([]);

        $this->assertSame('2026-02-14 00:00:00', $period->getUtcFrom());
        $this->assertSame('2026-03-15 23:59:59', $period->getUtcTo());
    }

    /**
     * @return void
     */
    public function testAnInvertedRangeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is after date_to');

        $this->resolver('UTC')->resolve(['date_from' => '2026-03-01', 'date_to' => '2026-01-01']);
    }

    /**
     * Two ways of saying the same thing is a question about which one won.
     *
     * @return void
     */
    public function testAPeriodAndAnExplicitRangeTogetherAreRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not both');

        $this->resolver('UTC')->resolve([
            'period' => 'last_month',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);
    }

    /**
     * @return void
     */
    public function testHalfAnExplicitRangeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('go together');

        $this->resolver('UTC')->resolve(['date_from' => '2026-01-01']);
    }

    /**
     * A report has to be reproducible from its arguments, so relative English
     * that strtotime would happily accept is refused.
     *
     * @return void
     */
    public function testARelativeDateExpressionIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be "YYYY-MM-DD"');

        $this->resolver('UTC')->resolve(['date_from' => 'next tuesday', 'date_to' => '2026-01-31']);
    }

    /**
     * @return void
     */
    public function testAnImpossibleDateIsRefusedRatherThanRolledOver(): void
    {
        $this->expectException(LocalizedException::class);

        $this->resolver('UTC')->resolve(['date_from' => '2026-02-30', 'date_to' => '2026-03-01']);
    }

    /**
     * @return void
     */
    public function testAnUnknownNamedPeriodIsRefusedWithTheRealOnes(): void
    {
        try {
            $this->resolver('UTC')->resolve(['period' => 'last_fortnight']);
            $this->fail('An unknown period must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('last_fortnight', $e->getMessage());
            $this->assertStringContainsString('last_30_days', $e->getMessage());
        }
    }

    /**
     * A year by day is 365 rows of numbers — not an answer, and nearly always a
     * month or a week that was meant.
     *
     * @return void
     */
    public function testTooManyBucketsAreRefusedWithAWayOut(): void
    {
        try {
            $this->resolver('UTC')->resolve(['period' => 'this_year'], null, true);
            $this->fail('A year of daily buckets must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('coarser granularity', $e->getMessage());
        }
    }

    /**
     * The same year at month granularity is twelve rows, which is fine.
     *
     * @return void
     */
    public function testTheSameRangeAtACoarserGranularityIsAccepted(): void
    {
        $period = $this->resolver('UTC')->resolve(
            ['period' => 'this_year', 'granularity' => 'month'],
            null,
            true
        );

        $this->assertSame('month', $period->getGranularity());
    }

    /**
     * @return void
     */
    public function testGranularityDefaultsToDayAndIsAbsentWhenNotAsked(): void
    {
        $this->assertSame(
            'day',
            $this->resolver('UTC')->resolve(['period' => 'last_7_days'], null, true)->getGranularity()
        );
        $this->assertSame(
            'none',
            $this->resolver('UTC')->resolve(['period' => 'last_7_days'])->getGranularity()
        );
    }

    /**
     * @return void
     */
    public function testAnUnknownGranularityIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be one of: day, week, month');

        $this->resolver('UTC')->resolve(['period' => 'last_7_days', 'granularity' => 'hour'], null, true);
    }

    /**
     * A timezone Magento accepted but PHP will not must not quietly become UTC
     * — every figure would be on the wrong day with nothing to say so.
     *
     * @return void
     */
    public function testAnUnusableStoreTimezoneIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('timezone PHP does not recognise');

        $this->resolver('Mars/Olympus_Mons')->resolve(['period' => 'today']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function calendarPeriodProvider(): array
    {
        return [
            'mtd ends today' => ['mtd', '2026-03-01 00:00:00', '2026-03-15 23:59:59'],
            'qtd ends today' => ['qtd', '2026-01-01 00:00:00', '2026-03-15 23:59:59'],
            'ytd ends today' => ['ytd', '2026-01-01 00:00:00', '2026-03-15 23:59:59'],
            'this_quarter runs to its end' => ['this_quarter', '2026-01-01 00:00:00', '2026-03-31 23:59:59'],
            'last_quarter crosses the year' => ['last_quarter', '2025-10-01 00:00:00', '2025-12-31 23:59:59'],
            'last_2_weeks is fourteen days' => ['last_2_weeks', '2026-03-02 00:00:00', '2026-03-15 23:59:59'],
            'last_90_days' => ['last_90_days', '2025-12-16 00:00:00', '2026-03-15 23:59:59'],
            'last_18_months rolls' => ['last_18_months', '2024-09-16 00:00:00', '2026-03-15 23:59:59'],
            'last_1_years rolls' => ['last_1_years', '2025-03-16 00:00:00', '2026-03-15 23:59:59'],
        ];
    }

    /**
     * @param string $name
     * @param string $from
     * @param string $to
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('calendarPeriodProvider')]
    public function testCalendarAndRollingPeriods(string $name, string $from, string $to): void
    {
        $period = $this->resolver('UTC')->resolve(['period' => $name]);

        $this->assertSame($from, $period->getUtcFrom());
        $this->assertSame($to, $period->getUtcTo());
    }

    /**
     * A rolling month from the 31st must not start inside the month it meant
     * to skip, which is what "-1 month" from a March 31st does.
     *
     * @return void
     */
    public function testARollingMonthClampsAShortMonth(): void
    {
        $zone = $this->createMock(TimezoneInterface::class);
        $zone->method('getConfigTimezone')->willReturn('UTC');
        $clock = $this->createMock(CoreDate::class);
        $clock->method('gmtTimestamp')->willReturn(1774958400); // 2026-03-31 12:00:00 UTC

        $period = (new PeriodResolver($zone, $clock))->resolve(['period' => 'last_1_months']);

        $this->assertSame('2026-03-01 00:00:00', $period->getUtcFrom());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badRelativeProvider(): array
    {
        return [
            'zero' => ['last_0_days'],
            'too many months' => ['last_121_months'],
            'unknown unit' => ['last_3_fortnights'],
            'leading zero' => ['last_07_days'],
        ];
    }

    /**
     * @param string $name
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRelativeProvider')]
    public function testANonsenseRelativePeriodIsRefused(string $name): void
    {
        $this->expectException(LocalizedException::class);

        $this->resolver('UTC')->resolve(['period' => $name]);
    }

    /**
     * February is compared with January, not with the 28 days before it.
     *
     * @return void
     */
    public function testThePreviousPeriodOfAMonthIsTheMonthBefore(): void
    {
        $resolver = $this->resolver('UTC');
        $previous = $resolver->comparison($resolver->resolve(['period' => 'last_month']), 'previous_period');

        $this->assertSame('2026-01-01 00:00:00', $previous->getUtcFrom());
        $this->assertSame('2026-01-31 23:59:59', $previous->getUtcTo());
    }

    /**
     * @return void
     */
    public function testThePreviousPeriodOfAQuarterIsTheQuarterBefore(): void
    {
        $resolver = $this->resolver('UTC');
        $previous = $resolver->comparison($resolver->resolve(['period' => 'last_quarter']), 'previous_period');

        $this->assertSame('2025-07-01 00:00:00', $previous->getUtcFrom());
        $this->assertSame('2025-09-30 23:59:59', $previous->getUtcTo());
    }

    /**
     * @return void
     */
    public function testThePreviousPeriodOfSevenDaysIsTheSevenBefore(): void
    {
        $resolver = $this->resolver('UTC');
        $previous = $resolver->comparison($resolver->resolve(['period' => 'last_7_days']), 'previous_period');

        $this->assertSame('2026-03-02 00:00:00', $previous->getUtcFrom());
        $this->assertSame('2026-03-08 23:59:59', $previous->getUtcTo());
    }

    /**
     * @return void
     */
    public function testThePreviousPeriodOfATimeRangeIsTheSameLength(): void
    {
        $resolver = $this->resolver('UTC');
        $previous = $resolver->comparison(
            $resolver->resolve(['date_from' => '2026-03-10 09:00:00', 'date_to' => '2026-03-10 17:00:00']),
            'previous_period'
        );

        $this->assertSame('2026-03-10 00:59:59', $previous->getUtcFrom());
        $this->assertSame('2026-03-10 08:59:59', $previous->getUtcTo());
    }

    /**
     * A year back keeps a month end a month end, leap day included.
     *
     * @return void
     */
    public function testThePreviousYearOfFebruaryIncludesTheLeapDay(): void
    {
        $resolver = $this->resolver('UTC');
        $previous = $resolver->comparison(
            $resolver->resolve(['date_from' => '2025-02-01', 'date_to' => '2025-02-28']),
            'previous_year'
        );

        $this->assertSame('2024-02-01 00:00:00', $previous->getUtcFrom());
        $this->assertSame('2024-02-29 23:59:59', $previous->getUtcTo());
    }

    /**
     * The comparison is computed in the store's calendar, so a week that
     * contains the spring change still starts and ends at local midnight.
     *
     * @return void
     */
    public function testTheComparisonIsCutAtLocalMidnightAcrossADaylightSavingChange(): void
    {
        $resolver = $this->resolver('Europe/Berlin');
        $previous = $resolver->comparison(
            $resolver->resolve(['date_from' => '2026-03-30', 'date_to' => '2026-04-05']),
            'previous_period'
        );

        $this->assertSame('2026-03-23 00:00:00', $previous->toArray()['from']);
        $this->assertSame('2026-03-22 23:00:00', $previous->getUtcFrom());
        $this->assertSame('2026-03-29 21:59:59', $previous->getUtcTo());
    }

    /**
     * @return void
     */
    public function testAnUnknownComparisonIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->resolver('UTC')->compareTo(['compare_to' => 'last_year']);
    }

    /**
     * @param string $timezone
     * @return PeriodResolver
     */
    private function resolver(string $timezone): PeriodResolver
    {
        $zone = $this->createMock(TimezoneInterface::class);
        $zone->method('getConfigTimezone')->willReturn($timezone);

        $clock = $this->createMock(CoreDate::class);
        $clock->method('gmtTimestamp')->willReturn(self::NOW);

        return new PeriodResolver($zone, $clock);
    }
}
