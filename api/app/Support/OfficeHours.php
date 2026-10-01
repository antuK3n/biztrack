<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Is City Hall open right now? [checklist, Login 6]
 *
 * One answer for every screen, from the server's clock in Manila time
 * (config/office_hours.php). The web app asks GET /office-hours and shows a
 * notice when the answer is no; the sign-in path asks the same question to
 * decide whether an officer's sign-in goes into the audit trail as
 * out-of-hours. Two callers, one rule, so the notice an officer reads and the
 * row the administrator reads cannot disagree about what "outside" meant.
 */
class OfficeHours
{
    public static function now(): Carbon
    {
        return Carbon::now()->setTimezone(self::timezone());
    }

    public static function isOpen(?Carbon $at = null): bool
    {
        $local = ($at ?? Carbon::now())->copy()->setTimezone(self::timezone());

        if (! in_array($local->dayOfWeekIso, (array) config('office_hours.days', []), true)) {
            return false;
        }

        if (in_array($local->toDateString(), (array) config('office_hours.holidays', []), true)) {
            return false;
        }

        $time = $local->format('H:i');

        // Opening minute counts as open, closing minute does not: at 17:00
        // sharp the counter has shut.
        return $time >= (string) config('office_hours.opens') && $time < (string) config('office_hours.closes');
    }

    /**
     * What the web app needs to word the notice. `now` is the server's instant,
     * sent so a client never has to trust its own clock.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $now = self::now();

        return [
            'open' => self::isOpen($now),
            'now' => $now->toIso8601String(),
            'timezone' => self::timezone(),
            'opens' => (string) config('office_hours.opens'),
            'closes' => (string) config('office_hours.closes'),
            'days' => array_values((array) config('office_hours.days')),
        ];
    }

    /**
     * Office time between two instants, in hours: only the stretches when City
     * Hall is open count.
     *
     * An office cannot act on a filing that arrives at 7 pm until 8 the next
     * morning, so measuring its turnaround on the wall clock charged it for
     * the night, and a Friday-evening filing for the whole weekend. This is
     * the clock the office-performance figures use instead (time at each
     * office, reply time to an applicant's message). A filing that arrives
     * after closing starts at the next opening; one finished before opening
     * the next day has taken no office time at all.
     *
     * Days, hours and the (empty) holiday list come from
     * config/office_hours.php, the same settings as the out-of-hours notice.
     */
    public static function hoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $tz = self::timezone();
        $start = CarbonImmutable::instance($from)->setTimezone($tz);
        $end = CarbonImmutable::instance($to)->setTimezone($tz);

        if ($end <= $start) {
            return 0.0;
        }

        [$openHour, $openMinute] = self::clock((string) config('office_hours.opens'));
        [$closeHour, $closeMinute] = self::clock((string) config('office_hours.closes'));
        $days = (array) config('office_hours.days', []);
        $holidays = (array) config('office_hours.holidays', []);

        $seconds = 0;
        for ($day = $start->startOfDay(); $day < $end; $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $days, true) || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }

            $opened = max($day->setTime($openHour, $openMinute), $start);
            $closed = min($day->setTime($closeHour, $closeMinute), $end);
            if ($closed > $opened) {
                $seconds += $closed->getTimestamp() - $opened->getTimestamp();
            }
        }

        return $seconds / 3600;
    }

    /** Hours in one office day (8:00 to 17:00 is 9), the unit of an "office day". */
    public static function hoursPerDay(): float
    {
        [$openHour, $openMinute] = self::clock((string) config('office_hours.opens'));
        [$closeHour, $closeMinute] = self::clock((string) config('office_hours.closes'));

        return (($closeHour * 60 + $closeMinute) - ($openHour * 60 + $openMinute)) / 60;
    }

    /** @return array{int, int} hour and minute of an "HH:MM" setting */
    private static function clock(string $hhmm): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm) + [1 => '0']);

        return [$hour, $minute];
    }

    private static function timezone(): string
    {
        return (string) config('office_hours.timezone', 'Asia/Manila');
    }
}
