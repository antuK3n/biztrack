<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The LGU's calendar: what "today", "this month" and "a working day" mean in
 * Malabon, for every figure that cuts time into days.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * The app clock is Asia/Manila since 5 October 2026 (config/app.php); this class still converts explicitly, so it holds whichever zone storage uses. The City
 * works in Asia/Manila, eight hours ahead, and every date a report or a
 * dashboard prints is a Manila date. Cutting days on the UTC clock was wrong
 * in two ways that both showed:
 *
 *  - `analytics:refresh` runs at 03:00 Manila (routes/console.php), which is
 *    19:00 UTC the PREVIOUS day. `CarbonImmutable::now()->startOfDay()` there
 *    is yesterday in Manila, so the nightly dashboard was a day behind: its
 *    "today", its "This Month" on the 1st, its expiry windows.
 *  - A report for "1 to 30 September" ran from 00:00 UTC on the 1st, which is
 *    08:00 Manila. Everything filed between midnight and eight in the morning
 *    of the 1st fell into August, and the last eight hours of the 30th fell out
 *    of the period altogether. Month buckets had the same edge.
 *
 * So every analytics query now asks this class where a Manila day or month
 * begins, as a UTC instant the database can compare against its stored UTC
 * timestamps, and every stored timestamp is read back into a Manila date
 * before it is bucketed. The conversion happens in PHP, never in SQL, so it is
 * the same on SQLite and on PostgreSQL — there is no date function the two
 * share, and a bucket computed in SQL would have to be written twice.
 *
 * The timezone is the one OfficeHours uses (config/office_hours.php), so "the
 * office is open" and "this happened today" cannot be asked of two different
 * clocks.
 *
 * ── Working days ────────────────────────────────────────────────────────────
 *
 * Counted between Manila DATES, weekends excluded, over the half-open interval
 * (from, to]: received and decided on the same day is zero working days,
 * received on Friday and decided on Monday is one. That is how
 * Ra11032::deadlineFor counts (`addWeekdays`), so a measured duration and a
 * statutory deadline are in the same unit.
 *
 * Public holidays are NOT excluded, here or in Ra11032, because the register
 * has no holiday calendar (docs/questions-for-malabon.md A11, B21). Counting a
 * holiday as a working day makes a turnaround read LONGER than it was, never
 * shorter: a filing this calls on time was on time.
 *
 * DashboardAnalytics, LguReports and OfficePerformanceAnalytics each had their
 * own copy of the working-day count, all on the UTC date. They share this one.
 */
final class ManilaCalendar
{
    public static function timezone(): string
    {
        return (string) config('office_hours.timezone', 'Asia/Manila');
    }

    /** The app's storage timezone (UTC). Instants handed to a query are in this zone. */
    private static function storageTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** The Manila date of an instant (now, by default), at 00:00 in Manila. */
    public static function today(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())
            ->setTimezone(self::timezone())
            ->startOfDay();
    }

    /**
     * A stored timestamp, read in Manila.
     *
     * Strings are parsed in the storage timezone, which is what the database
     * holds them in: a `timestamp` column carries no zone of its own.
     */
    public static function local(CarbonInterface|string $instant): CarbonImmutable
    {
        $instant = is_string($instant)
            ? CarbonImmutable::parse($instant, self::storageTimezone())
            : CarbonImmutable::instance($instant);

        return $instant->setTimezone(self::timezone());
    }

    /** "2026-09-30": the Manila date a stored timestamp falls on. */
    public static function dateOf(CarbonInterface|string $instant): string
    {
        return self::local($instant)->toDateString();
    }

    /** "2026-09": the Manila month a stored timestamp falls in. */
    public static function monthOf(CarbonInterface|string $instant): string
    {
        return self::local($instant)->format('Y-m');
    }

    /**
     * The instant a Manila date begins, in the storage timezone, ready to bind
     * into a query.
     *
     * A string is a Manila date ("2026-09-01"); a Carbon is taken for its
     * Manila date, whatever zone it carries.
     */
    public static function startOfDay(CarbonInterface|string $date): CarbonImmutable
    {
        return self::dateInManila($date)->startOfDay()->setTimezone(self::storageTimezone());
    }

    /**
     * The first instant AFTER a Manila date, in the storage timezone.
     *
     * Use with `<`, not `<=`: a period is [startOfDay(from), startOfNextDay(to)).
     * Half-open, because a stored timestamp has whole seconds and an
     * "end of day" of 23:59:59.999999 loses its fraction when it is bound into
     * a query — the last second of the day then depends on rounding.
     */
    public static function startOfNextDay(CarbonInterface|string $date): CarbonImmutable
    {
        return self::dateInManila($date)->addDay()->startOfDay()->setTimezone(self::storageTimezone());
    }

    /**
     * A period of Manila dates as the UTC instants that bound it:
     * [first instant of $from, first instant after $to).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return [self::startOfDay($from), self::startOfNextDay($to)];
    }

    /** Whole calendar days from one Manila date to another, signed. */
    public static function daysBetweenDates(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, 'UTC')->startOfDay()
            ->diffInDays(CarbonImmutable::parse($to, 'UTC')->startOfDay(), false);
    }

    /**
     * Working days between two instants, by their Manila dates.
     *
     * See the class note for the counting rule. Arithmetic rather than a
     * day-by-day walk: any seven consecutive days hold exactly five weekdays,
     * so only the remainder is walked. A three-year report over thousands of
     * filings would otherwise walk hundreds of thousands of days.
     */
    public static function workingDaysBetween(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        // Compared as plain dates in one fixed zone, so a DST rule anywhere
        // cannot make a "day" 23 or 25 hours long. Manila has none; the
        // arithmetic should not depend on that.
        $start = CarbonImmutable::parse(self::dateOf($from), 'UTC');
        $end = CarbonImmutable::parse(self::dateOf($to), 'UTC');

        if ($end <= $start) {
            return 0;
        }

        $days = (int) $start->diffInDays($end);
        $count = intdiv($days, 7) * 5;

        $cursor = $start;
        for ($i = 0; $i < $days % 7; $i++) {
            $cursor = $cursor->addDay();
            if ($cursor->isWeekday()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Working days between two instants, leaving out the stretches given.
     *
     * The stretches are [start, end) instants — time the filing sat with the
     * applicant, say — and only the part of each that falls inside
     * [from, to] is taken off. Because the count is by date over a half-open
     * interval it is additive (a→b plus b→c is a→c), so subtracting the
     * working days inside each stretch leaves exactly the working days outside
     * them. The stretches must not overlap one another; status history, which
     * is where they come from, is a sequence and cannot.
     *
     * @param  iterable<array{0: CarbonInterface, 1: CarbonInterface|null}>  $stretches  a null end runs to $to
     */
    public static function workingDaysOutside(CarbonInterface $from, CarbonInterface $to, iterable $stretches): int
    {
        $total = self::workingDaysBetween($from, $to);

        foreach ($stretches as [$start, $end]) {
            $start = max(CarbonImmutable::instance($start), CarbonImmutable::instance($from));
            $end = min(CarbonImmutable::instance($end ?? $to), CarbonImmutable::instance($to));
            if ($end > $start) {
                $total -= self::workingDaysBetween($start, $end);
            }
        }

        return max(0, $total);
    }

    /** A Manila date, at 00:00 Manila, from a date string or any Carbon. */
    private static function dateInManila(CarbonInterface|string $date): CarbonImmutable
    {
        return is_string($date)
            ? CarbonImmutable::parse($date, self::timezone())->startOfDay()
            : CarbonImmutable::instance($date)->setTimezone(self::timezone())->startOfDay();
    }
}
