<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract InspectionResource. */
class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * relationLoaded, not whenLoaded.
         *
         * `whenLoaded('application')` with a single argument returns a
         * MissingValue *object* when the relation is absent, and an object is
         * truthy — so this ternary took the "loaded" branch every time and
         * `$this->application` lazy-loaded a row. Invisible in the inspection
         * list, where the relation really is eager-loaded; a query per row
         * everywhere InspectionResource is nested without it, which is every
         * ApplicationResource (`inspections.department`, `inspections.inspector`
         * — no `inspections.application`) and the assignment detail page.
         */
        $app = $this->relationLoaded('application') ? $this->application : null;
        $address = $app && $app->relationLoaded('business') && $app->business && $app->business->relationLoaded('address')
            ? $app->business->address
            : null;

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'result' => $this->result?->value,
            'result_label' => $this->result?->label(),
            'scheduled_at' => optional($this->scheduled_at)->toISOString(),
            'conducted_at' => optional($this->conducted_at)->toISOString(),
            'findings' => $this->findings,
            /*
             * Whether a fresh visit may be booked off the back of this one.
             *
             * The screen could not work this out for itself. "Failed" it can
             * see; "is still the office's CURRENT visit" it cannot, because
             * nothing in this payload says a later visit exists. So a
             * superseded failure — an office that already failed twice — kept
             * offering the button, and the API answered every press with a 422.
             * A control whose only possible outcome is an error message is not
             * a control.
             *
             * Null, not false, when the filing was not loaded: the answer
             * depends on the application's status and on a query across the
             * department's other visits, and `$app === null` means this
             * response was never asked to know. False would be a claim; null
             * says the question was not put. Same reasoning as `particulars`
             * below — and the same reason it is gated rather than always
             * computed, since InspectionResource is nested in every
             * ApplicationResource and this would otherwise be a query per row.
             */
            'can_reinspect' => $app ? $this->canBeReinspected() : null,
            'department' => $this->relationLoaded('department') && $this->department ? [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'inspector' => $this->relationLoaded('inspector') && $this->inspector ? [
                'id' => $this->inspector->id,
                'name' => $this->inspector->name,
            ] : null,
            'application' => $app ? [
                'id' => $app->id,
                'tracking_id' => $app->tracking_id,
                'business' => $app->relationLoaded('business') && $app->business ? [
                    'name' => $app->business->name,
                ] : null,
                'address' => $address ? [
                    'line1' => $address->line1,
                    'barangay' => $address->relationLoaded('barangay') && $address->barangay ? [
                        'name' => $address->barangay->name,
                    ] : null,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                ] : null,
            ] : null,
            'particulars' => $this->particulars($app),
        ];
    }

    /**
     * The filing's own particulars, as the applicant submitted them.
     *
     * An officer opening a visit could previously read a status, a date, a
     * business name and their own name — and nothing about the premises they
     * were standing in front of. No address, no owner, no line of business, no
     * indication of which permit the visit is even for. The client's words:
     * "the inspection details should be the same as what was submitted in the
     * application form".
     *
     * The vocabulary is deliberately the permit certificate's
     * (PermitController::certificateData): owner_name / business_name /
     * trade_name / address / barangay / city / line_of_business. Those are the
     * same facts about the same business, and the certificate already had to
     * decide how to name them and how to survive a business that no longer
     * exists. A second vocabulary for one screen would mean two places to fix
     * the next time the register changes shape.
     *
     * Null, not a block of nulls, when the response was not asked to carry it.
     * Only InspectionController::show() eager-loads the deep relations this
     * needs; the list and the conduct/reschedule replies do not, and an object
     * full of nulls there would read as "the applicant filed nothing" rather
     * than "this response never went and looked". `permitTypes` is the gate
     * because it is loaded in exactly one place — show() — and nowhere else.
     *
     * @return array<string, mixed>|null
     */
    private function particulars(mixed $app): ?array
    {
        if (! $app || ! $app->relationLoaded('permitTypes')) {
            return null;
        }

        // Soft-deleted, legitimately: `Business` and `User` both go away while
        // their filings stay. Every read below is null-safe, and each value
        // answers null rather than inventing one, so the browser can say
        // "removed from register" instead of printing a blank it cannot explain.
        $business = $app->relationLoaded('business') ? $app->business : null;
        $address = $business?->relationLoaded('address') ? $business->address : null;

        return [
            // Which form this is. The GUI sheet heads itself "Application for
            // New Business Permit"; on a renewal that heading is wrong, so the
            // type travels with the particulars rather than being assumed.
            'application_type' => $app->application_type?->value,
            'business_name' => $business?->name,
            'trade_name' => $business?->trade_name,
            'registration_number' => $business?->registration_number,
            'tin' => $business?->tin,
            /*
             * `fullName()` before `name`. The register keeps the owner's name in
             * parts (first/middle/last/suffix) and `fullName()` joins them, but
             * it returns '' for an account created with only a display name —
             * which is most of the staff-seeded rows. Falling back to the
             * account name keeps a real person on the sheet instead of a dash;
             * falling back to the *applicant* would not, because whoever filed
             * the paperwork is frequently not the owner.
             */
            'owner_name' => ($business?->owner?->fullName() ?: null) ?? $business?->owner?->name,
            'address' => $address?->line1,
            'address_line2' => $address?->line2,
            'barangay' => $address?->relationLoaded('barangay') ? $address->barangay?->name : null,
            'city' => $address?->city,
            'province' => $address?->province,
            'postal_code' => $address?->postal_code,
            // Every declared line, joined — a business may carry more than one,
            // and an inspector needs to know all the trades on the premises, not
            // the first one the applicant happened to type.
            'line_of_business' => $business?->relationLoaded('lines')
                ? ($business->lines
                    ->map(fn ($l) => $l->relationLoaded('psicCode') && $l->psicCode
                        ? $l->psicCode->title
                        : $l->line_of_business)
                    ->filter()
                    ->implode(', ') ?: null)
                : null,
            // What the visit is actually for. A filing can carry several, and
            // the officer's checklist differs per permit.
            'permit_types' => $app->permitTypes
                ->map(fn ($pt) => ['code' => $pt->code, 'name' => $pt->name])
                ->values()
                ->all(),
        ];
    }
}
