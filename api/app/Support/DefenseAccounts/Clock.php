<?php

namespace App\Support\DefenseAccounts;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The time each step of a generated history happens at.
 *
 * ── Why the generator moves the clock at all ────────────────────────────────
 *
 * Two rules make "now" matter. An office books a visit only on a weekday
 * between 8:00 AM and 5:00 PM (checklist 2026-09-27, manage item 4), so a run
 * started at 10 PM would be refused at the first booking. And the expired
 * scenarios need a Business Permit ISSUED in 2025, which only a filing made
 * in 2025 can produce — `issuePermitFor` dates a permit from the day it is
 * granted, and a raw `valid_until` written by hand is exactly the shortcut
 * this generator exists not to take.
 *
 * So each owner's history is played on days of its own, in order: the owner
 * files on one weekday, BPLO reads it on the next, and so on, every step a
 * few minutes after the last. The days are in the past, so nothing a run
 * writes is dated after the moment it ran, and a waiting filing has waited
 * a believable one or two working days rather than none.
 *
 * Carbon's test clock is the mechanism because it is the one every `now()`
 * in the app already reads; `release()` hands the real clock back, and the
 * command calls it in a `finally` so a failed step cannot leave it moved.
 */
final class Clock
{
    private CarbonImmutable $now;

    private int $ticks = 0;

    public function __construct()
    {
        $this->now = CarbonImmutable::now();
    }

    /**
     * Today in Manila by the wall clock. Read through PHP's own DateTime,
     * which no test clock reaches, so it is the real day even mid-run.
     */
    public static function realToday(): CarbonImmutable
    {
        return CarbonImmutable::instance(new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->startOfDay();
    }

    /** The weekday `$n` weekdays before `$day` (1 = the one before). */
    public static function weekdaysBefore(CarbonImmutable $day, int $n): CarbonImmutable
    {
        $at = $day;
        while ($n > 0) {
            $at = $at->subDay();
            if (! $at->isWeekend()) {
                $n--;
            }
        }

        return $at;
    }

    /** The weekday `$n` weekdays after `$day`. */
    public static function weekdaysAfter(CarbonImmutable $day, int $n): CarbonImmutable
    {
        $at = $day;
        while ($n > 0) {
            $at = $at->addDay();
            if (! $at->isWeekend()) {
                $n--;
            }
        }

        return $at;
    }

    /** Stand the clock at `$day`, at `$time` Manila. */
    public function on(CarbonImmutable $day, string $time = '08:30'): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->set($day->setTimezone('Asia/Manila')->setTime($hour, $minute));
    }

    /**
     * A few minutes later: the gap between one click and the next.
     *
     * Two to four minutes, varied by a counter rather than at random, so a
     * second run on an empty database writes the same history.
     */
    public function tick(): CarbonImmutable
    {
        $this->ticks++;

        return $this->set($this->now->addMinutes(2 + $this->ticks % 3));
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    /** Give the app the real clock back. */
    public function release(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function set(CarbonImmutable $when): CarbonImmutable
    {
        $this->now = $when;
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);

        return $when;
    }
}
