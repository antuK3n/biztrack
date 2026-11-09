<?php

namespace App\Support;

use Closure;

/**
 * The shape of a contact detail, checked the same way on every door.
 *
 * Client, 10 November 2026 ("screen + server"): the business form took any
 * text as a mobile, a landline, an emergency number or a website, and the
 * correction card after a Return took the same boxes with no rule at all.
 * The browser has refused bad ones since September (web/src/lib/fieldRules.ts
 * and lib/phone.ts); the API did not, so anything that was not the wizard
 * stored whatever it sent.
 *
 * One copy, read by BusinessController::validateBusiness (first filing),
 * ApplicationController::validateCorrections (a returned field) and
 * OfficeFormRules (the OBO sheet's Tel. No.). Each rule mirrors its browser
 * twin, named on the method; change both together.
 *
 * Blank is never judged here. Whether a box is required is the form's
 * question, and every caller already pairs these with `nullable`.
 */
class ContactRules
{
    public const MOBILE_MESSAGE = 'Enter a valid mobile number.';

    public const LANDLINE_MESSAGE = 'Enter a valid landline number.';

    public const PHONE_MESSAGE = 'Enter a valid phone number.';

    public const EMERGENCY_MESSAGE = 'Enter 11 digits, starting 09.';

    public const WEBSITE_MESSAGE = 'Enter a valid website address.';

    public const NAME_MESSAGE = 'A name cannot contain numbers.';

    /**
     * A PH mobile, written any of the ways PhMobile reads one (09…, 9…,
     * 639…, +639…). The wizard sends +639XXXXXXXXX; `mobileValid` in
     * lib/phone.ts is the browser's copy.
     */
    public static function mobile(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! self::blank($value) && (! is_string($value) || PhMobile::e164($value) === null)) {
                $fail(self::MOBILE_MESSAGE);
            }
        };
    }

    /**
     * A landline with or without its area code, or a mobile: digits and the
     * usual separators, 7 to 13 digits. `phoneValid` in lib/fieldRules.ts.
     */
    public static function phone(string $message = self::PHONE_MESSAGE): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message) {
            if (self::blank($value)) {
                return;
            }
            $text = is_scalar($value) ? trim((string) $value) : '';
            $digits = strlen((string) preg_replace('/\D/', '', $text));
            if (preg_match('/^[+\d\s().-]+$/', $text) !== 1 || $digits < 7 || $digits > 13) {
                $fail($message);
            }
        };
    }

    /**
     * The emergency contact: a mobile, which is what the screen's hint asks
     * for ("11 digits, starting 09"). Inspectors call it from the field, so a
     * landline that may ring in an empty office is not what the box is for.
     * The +63 spelling of the same number is let through.
     */
    public static function emergencyNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (self::blank($value)) {
                return;
            }
            $plain = is_string($value) ? (StaffCredentials::normaliseMobile($value) ?? '') : '';
            if (preg_match('/^(?:09\d{9}|\+?639\d{9})$/', $plain) !== 1) {
                $fail(self::EMERGENCY_MESSAGE);
            }
        };
    }

    /**
     * A host, with or without a scheme and a path. `websiteValid` in
     * lib/fieldRules.ts, kept as lenient: the mistake this box attracts is a
     * wrong kind of answer (an e-mail, a phone number, a sentence).
     */
    public static function website(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (self::blank($value)) {
                return;
            }
            $text = is_string($value) ? trim($value) : '';
            if (str_contains($text, '@')
                || preg_match('/\s/', $text) === 1
                || preg_match('/^(https?:\/\/)?[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i', $text) !== 1) {
                $fail(self::WEBSITE_MESSAGE);
            }
        };
    }

    /** A person's name: letters and punctuation, never a digit. */
    public static function personName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && preg_match('/\d/', $value) === 1) {
                $fail(self::NAME_MESSAGE);
            }
        };
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
