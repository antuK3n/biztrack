<?php

namespace App\Support;

use App\Models\Business;

/**
 * The few facts from the application form that a Manage Applications row
 * shows under the business name (3 November 2026: "Make sure not all fields
 * are displayed, only the most important ones according to the application
 * form"): who owns it as the form names them, where it is, and what it does.
 *
 * Read only from relations the caller eager-loaded (EAGER), never lazily: a
 * queue page renders this once per row.
 */
final class QueueSummary
{
    /** The relations summary() reads, under `business.`. */
    public const EAGER = [
        'business.address:id,business_id,barangay_id',
        'business.address.barangay:id,name',
        'business.lines:id,business_id,psic_code_id,line_of_business',
        'business.lines.psicCode:id,title',
        'business.owners:id,business_id,surname,given_name,middle_name,suffix,is_primary',
    ];

    /**
     * @return array{owner: string|null, barangay: string|null, line: string|null, more_lines: int}
     */
    public static function summary(Business $business): array
    {
        $lines = $business->relationLoaded('lines')
            ? $business->lines
                ->map(static fn ($l) => trim((string) ($l->line_of_business ?: ($l->relationLoaded('psicCode') ? $l->psicCode?->title : ''))))
                ->filter()
                ->unique()
                ->values()
            : collect();

        return [
            'owner' => self::owner($business),
            'barangay' => $business->relationLoaded('address') && $business->address?->relationLoaded('barangay')
                ? $business->address->barangay?->name
                : null,
            'line' => $lines->first(),
            'more_lines' => max(0, $lines->count() - 1),
        ];
    }

    /**
     * The owner as the form names them (item 11/12): the primary owner row,
     * else the president or officer named for a corporation. Null leaves the
     * screen to fall back to the account that filed.
     */
    private static function owner(Business $business): ?string
    {
        if ($business->relationLoaded('owners')) {
            $owner = $business->owners->firstWhere('is_primary', true) ?? $business->owners->first();
            if ($owner !== null) {
                $name = trim(implode(' ', array_filter([
                    $owner->given_name,
                    $owner->middle_name ? mb_substr($owner->middle_name, 0, 1).'.' : null,
                    $owner->surname,
                    $owner->suffix,
                ])));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $president = trim((string) ($business->getAttributes()['president_officer_name'] ?? ''));

        return $president === '' ? null : $president;
    }
}
