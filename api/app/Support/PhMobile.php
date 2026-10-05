<?php

namespace App\Support;

/**
 * A Philippine mobile number in the one form the SMS gateway takes: +639XXXXXXXXX.
 *
 * Edit Profile and the staff forms accept only `09XXXXXXXXX`
 * (AuthController::updateProfile, StaffCredentials), but sign-up checks only
 * the length, so older rows can hold `9…`, `639…` or `+639…`. All four are the
 * same number and all four are accepted, after taking out the punctuation
 * StaffCredentials::normaliseMobile takes out. Anything else — a landline, a
 * digit short, "09d" — is null, and the caller skips the text.
 */
class PhMobile
{
    public static function e164(?string $number): ?string
    {
        $digits = StaffCredentials::normaliseMobile($number) ?? '';

        return preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m) === 1
            ? '+63'.$m[1]
            : null;
    }
}
