<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * What a staff account's password and mobile number have to look like.
 *
 * ── Why this is one class and not three copies ──────────────────────────────
 *
 * Three endpoints accept these two fields and all three disagreed. Owner
 * registration enforced `^09\d{9}$` on one path and `max:20` on another, and
 * the admin directory — the screen that mints every officer account in the
 * city — enforced nothing at all beyond a length. The register already carries
 * what that cost: `0917123456` (ten digits), `091712345646` (twelve) and
 * `091234567` (nine) are all in the users table, each one a number nobody can
 * be reached on, entered through a form that said they were fine.
 *
 * A rule that lives in one place is a rule the next endpoint inherits instead
 * of reinventing.
 */
final class StaffCredentials
{
    /**
     * A Philippine mobile number, as the network actually issues them.
     *
     * Eleven digits beginning 09. `+639…` and numbers typed with spaces or
     * dashes are accepted by `normaliseMobile()` before they reach this, so the
     * rule itself can stay a single shape rather than a regex with four arms —
     * the form is forgiving, the column is not.
     */
    public static function mobileRules(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/^09\d{9}$/'];
    }

    /**
     * What a staff password has to be.
     *
     * ── Why stricter than the eight characters this used to ask for ─────────
     *
     * These accounts approve permits, release clearances and can reassign any
     * office's caseload. `PasswordRule::min(8)` alone accepts "password" — it
     * checks a length and nothing else — and the admin who types it is choosing
     * on somebody else's behalf, so the person who has to live with the weak
     * password never got a say in it.
     *
     * Twelve with a mix of cases, a digit and a symbol is the shape a passphrase
     * takes anyway ("Malabon-City-2026!"), so it is not a puzzle to satisfy; it
     * is the difference between a guessable account and one that is not.
     *
     * Deliberately NOT `uncompromised()`. That rule calls the Have I Been Pwned
     * API over the network on every submit, so an office with the internet down
     * — an ordinary Tuesday here — either waits on a timeout or has the check
     * silently pass, and neither is a security control you can describe to
     * anyone. The rules above hold without a network.
     */
    public static function passwordRules(bool $required): PasswordRule|array
    {
        $rule = PasswordRule::min(12)->mixedCase()->numbers()->symbols();

        return $required ? ['required', $rule] : ['nullable', $rule];
    }

    /**
     * The messages, phrased as the fix rather than as the complaint.
     *
     * "The mobile number format is invalid" tells somebody staring at a field
     * they have already looked at twice precisely nothing (WCAG 3.3.3 asks for
     * a suggestion, not a verdict), so each one carries an example.
     */
    public static function messages(): array
    {
        return [
            'mobile_number.regex' => 'A mobile number is 11 digits and starts with 09, as in 09171234567.',
            'mobile_number.required' => 'A mobile number is needed — it is how this officer is reached about their filings.',
            'password.required' => 'Set a password for this account.',
        ];
    }

    /**
     * Tidy what was typed into the one shape the column stores.
     *
     * People type their own number the way they say it: `+63 917 123 4567`,
     * `0917-123-4567`, `0917 123 4567`. All three are the same number, and
     * refusing them teaches nothing — it just makes the admin retype what they
     * already got right. What must not be tolerated is a number of the wrong
     * LENGTH, because that is not a formatting preference, it is a wrong
     * number; those still fail the rule above.
     *
     * Anything this does not recognise is returned untouched, so the validator
     * rejects it and says why, rather than this quietly mangling it into
     * something that passes.
     */
    public static function normaliseMobile(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';

        // +639171234567 and 639171234567 are both 09171234567.
        if (preg_match('/^\+?63(9\d{9})$/', $digits, $m) === 1) {
            return '0'.$m[1];
        }

        return $digits;
    }
}
