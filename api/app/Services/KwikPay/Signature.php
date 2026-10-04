<?php

namespace App\Services\KwikPay;

/**
 * KwikPay's MD5 signature (merchant docs §2), both directions.
 *
 *   1. drop `sign`
 *   2. sort the remaining keys ASCII ascending
 *   3. join as key=value with &
 *   4. append &key=<merchant key>
 *   5. md5, lowercase hex
 *
 * ── The traps the docs' FAQ lists, and how each is avoided here ─────────────
 *
 * - Sign over the fields ACTUALLY RECEIVED, never a fixed list: `remark` is sent
 *   only when KwikPay enables it for the account, and when present it is
 *   signed. `verify()` takes whatever arrived.
 * - Sign the RAW values: `amount` arrives as "100.000000" and that string is
 *   what was hashed. Nothing here casts or formats a value; callers must pass
 *   the fields before any normalisation (the callback route is excluded from
 *   Laravel's TrimStrings and ConvertEmptyStringsToNull for this reason).
 * - ASCII order, not natural or locale order: `SORT_STRING` compares bytes.
 * - Compare in constant time (`hash_equals`), so the check does not leak how
 *   many leading characters of a forged signature were right.
 */
class Signature
{
    /** @param  array<string, scalar|null>  $fields */
    public static function make(array $fields, string $key): string
    {
        unset($fields['sign']);
        ksort($fields, SORT_STRING);

        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = $name.'='.self::raw($value);
        }

        return md5(implode('&', $pairs).'&key='.$key);
    }

    /** @param  array<string, mixed>  $fields */
    public static function verify(array $fields, string $key): bool
    {
        $given = $fields['sign'] ?? null;
        if (! is_string($given) || $given === '' || $key === '') {
            return false;
        }

        foreach ($fields as $value) {
            // A nested value cannot have been signed by a flat key=value scheme.
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }

        return hash_equals(self::make($fields, $key), strtolower($given));
    }

    /*
     * The value as it would be written into the string. Booleans do not occur
     * in KwikPay's payloads; they are spelled the way JSON would so that a
     * JSON-bodied request signs the same as its form-encoded twin.
     */
    private static function raw(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }
}
