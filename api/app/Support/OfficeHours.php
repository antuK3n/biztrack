<?php

namespace App\Support;

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

    private static function timezone(): string
    {
        return (string) config('office_hours.timezone', 'Asia/Manila');
    }
}
