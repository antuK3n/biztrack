<?php

namespace App\Support\LegacyImport;

/**
 * BizTrack's own import template: the columns, one example row, and a guide.
 *
 * ── Why our template and not the old system's export ───────────────────────
 *
 * MISD has not sent a sample of the city's old data yet (docs/misd-questions.md
 * Q10), so nobody here knows its column names, its date format or how it
 * spells a barangay. Guessing at an export we have never seen would make the
 * importer quietly wrong the day the real one arrives. Instead BizTrack states
 * what it needs, in plain columns, and whoever holds the old data maps it once —
 * in Excel.
 *
 * One row is one business and, optionally, one of its permits. A business with
 * three permits is three rows that repeat the business columns; a business with
 * no permit on record is one row with the permit columns left empty.
 *
 * The guide below is the single source for the screen's column table and for
 * the downloadable CSV, so the two cannot drift apart.
 */
class Template
{
    /**
     * column => [required?, what it holds].
     *
     * "Required" here means required on every row; the permit columns are
     * required together, only when the row carries a permit at all.
     *
     * @var array<string, array{0: bool|string, 1: string}>
     */
    public const COLUMNS = [
        'legacy_business_id' => [true, 'The old system’s own ID for the business. Re-importing a row with the same ID updates it instead of adding a copy.'],
        'business_name' => [true, 'Registered business name.'],
        'trade_name' => [false, 'Trade name, if different.'],
        'organization_type' => [false, 'sole_proprietorship, partnership, corporation or cooperative (DTI, SEC or CDA are also understood).'],
        'registration_number' => [false, 'DTI, SEC or CDA registration number.'],
        'business_account_no' => [false, 'The account number the city already uses for this business. Left empty, BizTrack gives it a new BP- number.'],
        'tin' => [false, 'Tax Identification Number.'],
        'owner_legacy_id' => [false, 'The old system’s ID for the owner, so one owner of several businesses is one person.'],
        'owner_first_name' => [true, 'Owner’s given name.'],
        'owner_middle_name' => [false, 'Owner’s middle name.'],
        'owner_last_name' => [true, 'Owner’s surname.'],
        'owner_suffix' => [false, 'Jr., Sr., III and so on.'],
        'owner_email' => [false, 'Owner’s email. If it belongs to an existing business owner account, the business is linked to that account.'],
        'owner_mobile' => [false, 'Owner’s mobile number.'],
        'address_line' => [true, 'House or building number and street.'],
        'barangay' => [true, 'One of Malabon’s 21 barangays, spelled as BizTrack lists it (capitals do not matter).'],
        'legacy_permit_id' => ['permit', 'The old system’s own ID for the permit. Needed on any row that has a permit.'],
        'permit_type' => ['permit', 'BUSINESS, SANITARY, FSIC, OCCUPANCY, CEC or ZONING — or the permit’s full name.'],
        'permit_number' => ['permit', 'The number printed on the certificate.'],
        'valid_from' => ['permit', 'First day the permit is valid: YYYY-MM-DD, or MM/DD/YYYY with the month first.'],
        'valid_until' => ['permit', 'Last day the permit is valid: YYYY-MM-DD, or MM/DD/YYYY with the month first.'],
        'permit_status' => [false, 'active, expired, suspended or revoked. Left empty, it is worked out from valid_until.'],
    ];

    /**
     * An example row — plainly an example, so nobody imports it by mistake as
     * a real business (AGENTS.md §6.4: a placeholder shows the shape of an
     * answer, never an answer).
     *
     * @var array<string, string>
     */
    public const EXAMPLE = [
        'legacy_business_id' => 'OLD-000123',
        'business_name' => 'EXAMPLE ROW - DELETE BEFORE IMPORTING',
        'trade_name' => '',
        'organization_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-0000000',
        'business_account_no' => '',
        'tin' => '000-000-000-000',
        'owner_legacy_id' => 'OWN-000045',
        'owner_first_name' => 'Juan',
        'owner_middle_name' => '',
        'owner_last_name' => 'Example',
        'owner_suffix' => '',
        'owner_email' => '',
        'owner_mobile' => '',
        'address_line' => '12 Sample Street',
        'barangay' => 'Tugatog',
        'legacy_permit_id' => 'PRM-2025-000987',
        'permit_type' => 'BUSINESS',
        'permit_number' => 'OLD-MP-2025-0987',
        'valid_from' => '2025-01-20',
        'valid_until' => '2025-12-31',
        'permit_status' => '',
    ];

    /** @return list<string> */
    public static function headers(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** @return list<string> */
    public static function permitColumns(): array
    {
        return array_keys(array_filter(self::COLUMNS, fn ($c) => $c[0] === 'permit'));
    }

    /** The template file itself: the header row and the example row. */
    public static function csv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::headers(), escape: '');
        fputcsv($out, array_values(self::EXAMPLE), escape: '');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * The column guide as the screen shows it.
     *
     * @return list<array{column: string, required: string, description: string}>
     */
    public static function guide(): array
    {
        return array_map(fn (string $column, array $def) => [
            'column' => $column,
            'required' => match ($def[0]) {
                true => 'always',
                'permit' => 'with a permit',
                default => 'no',
            },
            'description' => $def[1],
        ], array_keys(self::COLUMNS), self::COLUMNS);
    }
}
