<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * The Debug page's system switches: each a `settings` row that overrides what
 * the env would decide, read at the moment it matters, so a change takes
 * effect on the next request with no restart [Ken, 2026-10-04].
 *
 *   sign_in_codes  e-mail a code after the password (on / off)
 *   captcha        Turnstile on the sign-in form (on / off)
 *   office_hours   the "City offices are closed" notice (auto / open / closed)
 *   pretend_date   the date renewal deadlines and late penalties are judged
 *                  against (a date, or none)
 *
 * With no row, every switch answers exactly what the code answered before it
 * existed: sign-in codes follow EmailSwitch, the captcha follows
 * TURNSTILE_SECRET_KEY, the notice follows the clock, and there is no pretend
 * date. Clearing a switch (`default`) goes back to that.
 *
 * ── One place each ─────────────────────────────────────────────────────────
 *
 * Each switch is read through one function here and the code that used to
 * decide reads that function instead: AuthController::login asks
 * signInCodes(), Turnstile::enabled() asks captchaOn(), OfficeHours::status()
 * asks officeHoursOverride(), and BusinessDate asks pretendDate(). Nothing
 * else reads the rows.
 *
 * ── Turning on what cannot work is refused ─────────────────────────────────
 *
 * Sign-in codes cannot go on while mail goes nowhere, and the captcha cannot
 * go on without its secret: either would stop every sign-in, for a reason no
 * one at the keyboard could see. Turning them OFF is always allowed. And a
 * switch already on whose requirement later disappears (mail switched to
 * `log`) answers off, for the same reason.
 *
 * Only set() writes; the Debug page's controller audits each change as
 * `debug.switches` with before and after.
 */
class SystemSwitches
{
    /** The container key of the per-request memo of stored switches; see stored(). */
    public const MEMO = 'biztrack.system-switches';

    public const SIGN_IN_CODES = 'sign_in_codes';

    public const CAPTCHA = 'captcha';

    public const OFFICE_HOURS = 'office_hours';

    public const PRETEND_DATE = 'pretend_date';

    public const NAMES = [self::SIGN_IN_CODES, self::CAPTCHA, self::OFFICE_HOURS, self::PRETEND_DATE];

    /** How far the pretend date may sit from today, either way. */
    public const PRETEND_MAX_YEARS = 3;

    /** Whether a right password earns a code by e-mail before a session. */
    public static function signInCodes(): bool
    {
        if (! EmailSwitch::on()) {
            return false;
        }

        return self::stored(self::SIGN_IN_CODES) !== 'off';
    }

    /** Whether the sign-in form's captcha is checked. */
    public static function captchaOn(): bool
    {
        if (! Turnstile::configured()) {
            return false;
        }

        return self::stored(self::CAPTCHA) !== 'off';
    }

    /** `open` or `closed` when the notice is forced, null when the clock decides. */
    public static function officeHoursOverride(): ?string
    {
        $value = self::stored(self::OFFICE_HOURS);

        return in_array($value, ['open', 'closed'], true) ? $value : null;
    }

    /** The date renewal deadlines and late penalties are judged against, or null. */
    public static function pretendDate(): ?CarbonImmutable
    {
        $value = self::stored(self::PRETEND_DATE);
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every switch as the Debug page shows it: what it is now, whether a row
     * is overriding the default, and whether it could be turned on.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function state(): array
    {
        return [
            self::SIGN_IN_CODES => [
                'on' => self::signInCodes(),
                'overridden' => self::stored(self::SIGN_IN_CODES) !== null,
                'can_turn_on' => EmailSwitch::on(),
                'why_not' => EmailSwitch::on() ? null : self::noMailReason(),
            ],
            self::CAPTCHA => [
                'on' => self::captchaOn(),
                'overridden' => self::stored(self::CAPTCHA) !== null,
                'can_turn_on' => Turnstile::configured(),
                'why_not' => Turnstile::configured() ? null : self::noCaptchaReason(),
            ],
            self::OFFICE_HOURS => [
                'mode' => self::officeHoursOverride() ?? 'auto',
                'open_by_the_clock' => OfficeHours::isOpen(),
            ],
            self::PRETEND_DATE => [
                'date' => self::pretendDate()?->toDateString(),
                'real_today' => CarbonImmutable::today()->toDateString(),
            ],
        ];
    }

    /**
     * Change one switch. `default` (or null, for the date) clears the row.
     * Returns the switch's state before and after, for the audit row.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     *
     * @throws \InvalidArgumentException naming what is wrong, in words for the page
     */
    public static function set(string $name, ?string $value): array
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown switch: {$name}.");
        }

        $before = self::state()[$name];
        $value = $value === null ? null : trim($value);

        $stored = match ($name) {
            self::SIGN_IN_CODES => self::onOff($value, EmailSwitch::on(), self::noMailReason()),
            self::CAPTCHA => self::onOff($value, Turnstile::configured(), self::noCaptchaReason()),
            self::OFFICE_HOURS => self::officeHoursValue($value),
            self::PRETEND_DATE => self::pretendValue($value),
        };

        Setting::write('switch.'.$name, $stored);
        unset(app(self::MEMO)[$name]);

        return ['before' => $before, 'after' => self::state()[$name]];
    }

    private static function onOff(?string $value, bool $possible, string $whyNot): ?string
    {
        return match ($value) {
            'on' => $possible ? 'on' : throw new \InvalidArgumentException($whyNot),
            'off' => 'off',
            'default', null, '' => null,
            default => throw new \InvalidArgumentException('Use on, off or default.'),
        };
    }

    private static function officeHoursValue(?string $value): ?string
    {
        return match ($value) {
            'open', 'closed' => $value,
            'auto', 'default', null, '' => null,
            default => throw new \InvalidArgumentException('Use auto, open or closed.'),
        };
    }

    private static function pretendValue(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === 'default') {
            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Give the date as YYYY-MM-DD.');
        }

        $today = CarbonImmutable::today();
        if ($date->lt($today->subYears(self::PRETEND_MAX_YEARS)) || $date->gt($today->addYears(self::PRETEND_MAX_YEARS))) {
            throw new \InvalidArgumentException('Pick a date within '.self::PRETEND_MAX_YEARS.' years of today.');
        }

        return $value;
    }

    private static function noMailReason(): string
    {
        return 'Mail goes nowhere on this server (the driver is '.config('mail.default').'), so a sign-in code could never arrive and nobody could sign in.';
    }

    private static function noCaptchaReason(): string
    {
        return 'TURNSTILE_SECRET_KEY is not set on this server, so the captcha cannot be checked.';
    }

    /*
     * Null when the row is missing or the settings table cannot be read: every
     * switch then answers its default, which is how the code behaved before
     * the switches existed.
     */
    private static function stored(string $name): ?string
    {
        /*
         * Read once per request, not once per call. The pretend date is asked
         * for by every permit row's "days left", so a 200-row register read the
         * same settings row 200 times. The memo is a scoped binding: a new
         * request, queue job or test starts empty, and set() forgets the switch
         * it writes, so a flip is seen at once.
         */
        $memo = app(self::MEMO);
        if ($memo->offsetExists($name)) {
            return $memo[$name];
        }

        try {
            return $memo[$name] = Setting::read('switch.'.$name);
        } catch (QueryException) {
            return null;
        }
    }
}
