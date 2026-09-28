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
     * Eleven digits beginning 09, and nothing else. `+63…` used to be converted
     * before it reached here; the client asked for that to go [28 September
     * 2026] and they are right that one shape is easier to teach at a counter
     * than two that look different and mean the same.
     */
    public static function mobileRules(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/^09\d{9}$/'];
    }

    /**
     * What a staff password has to be.
     *
     * ── Six, and all four kinds of character ────────────────────────────────
     *
     * The length was eight and nothing else, which accepts "password" — it
     * checks a count and no more, and the admin typing it is choosing on
     * somebody else's behalf, so the person who has to live with a weak one
     * never got a say.
     *
     * It is six now, on the client's instruction [28 September 2026], with the
     * four kinds kept: a capital, a small letter, a digit and a symbol. Those
     * are what stop "password" and "123456", which is the failure actually
     * seen in the field; six characters drawn from four classes is a smaller
     * search space than a longer passphrase would give, and that trade is the
     * client's to make. Nothing here is the last line anyway — sign-in locks
     * an account after repeated failures (`AuthController::login`), so an
     * online guess does not get many tries.
     *
     * Deliberately NOT `uncompromised()`: see below.
     *
     * Deliberately NOT `uncompromised()`. That rule calls the Have I Been Pwned
     * API over the network on every submit, so an office with the internet down
     * — an ordinary Tuesday here — either waits on a timeout or has the check
     * silently pass, and neither is a security control you can describe to
     * anyone. The rules above hold without a network.
     */
    public static function passwordRules(bool $required): PasswordRule|array
    {
        $rule = PasswordRule::min(6)->mixedCase()->numbers()->symbols();

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
     * Take out what a person might put BETWEEN the digits, and nothing more.
     *
     * `0917-123-4567` and `0917 123 4567` are the same number written with
     * punctuation, and refusing them teaches nothing — it just makes the admin
     * retype what they already got right.
     *
     * `+63…` is no longer converted. It was, and the client asked for that to
     * go [28 September 2026: *"i want 09 at 11 digits lang"*]: one shape is
     * easier to teach at a counter than two that look different and mean the
     * same. A number in any other form is now returned untouched, fails the
     * rule above and is told why — which is the honest outcome. Quietly
     * rewriting it would be this method deciding what the admin meant.
     */
    public static function normaliseMobile(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/[\s\-().]/', '', trim($value)) ?? '';
    }
}
