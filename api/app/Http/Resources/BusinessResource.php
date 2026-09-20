<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the contract Business resource shape. */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /*
             * The business permit this shop is trading on right now, or null.
             *
             * Here so a chooser can name it without a request per business.
             * The amendment entry folds the permit into the business option —
             * "Pedro's Snack Bar — MCB-2026-000003" — because a business has
             * exactly one current business permit and asking which one is a
             * question with a single possible answer (client, 19 September
             * 2026).
             *
             * Null is NOT a data fault. A business row exists from the moment
             * somebody starts filing, so a shop still applying for its first
             * permit has none yet — measured: three of the five businesses on
             * the register, all with an application in flight. Such a business
             * has nothing to amend, and the chooser leaves it out rather than
             * offering it with an apologetic label.
             *
             * `whenLoaded`, so a caller that has not asked for the relation
             * gets no key at all rather than a null it would read as "this
             * business holds no permit".
             */
            'current_business_permit' => $this->whenLoaded(
                'currentBusinessPermit',
                fn () => $this->currentBusinessPermit === null ? null : [
                    'id' => $this->currentBusinessPermit->id,
                    'permit_number' => $this->currentBusinessPermit->permit_number,
                    'valid_until' => $this->currentBusinessPermit->valid_until?->toDateString(),
                ],
            ),
            'trade_name' => $this->trade_name,
            'registration_type' => $this->registration_type,
            'registration_number' => $this->registration_number,
            'tin' => $this->tin,
            'ban' => $this->ban,
            'is_active' => (bool) $this->is_active,
            // Unified-form premises and contact block.
            'is_rented' => (bool) $this->is_rented,
            'lessor_name' => $this->lessor_name,
            'lessor_address' => $this->lessor_address,
            'lessor_contact' => $this->lessor_contact,
            'monthly_rental' => $this->monthly_rental,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_number' => $this->emergency_contact_number,
            /*
             * BPLO paper-form answers. These have to come back out or the wizard
             * cannot restore them, and a reopened draft would send blanks over
             * the top of what the applicant typed on the next autosave — the
             * failure that made `monthly_rental` worth its own comment above.
             *
             * Item B6, then A13-A15 (null for a sole proprietorship by design;
             * see BusinessController::paperFormFields), then B8/B7.
             */
            'economic_organization' => $this->economic_organization,
            'economic_organization_others' => $this->economic_organization_others,
            'president_officer_name' => $this->president_officer_name,
            'citizenship' => $this->citizenship,
            'capital_participation_filipino' => $this->capital_participation_filipino,
            // BPLO item B7. A column that existed and was written by nothing
            // until the wizard started asking for it.
            'capital_investment' => $this->capital_investment,
            'has_tax_incentives' => (bool) $this->has_tax_incentives,
            // Owner-visible standing (p006 blacklist modal reads this).
            'status' => $this->status ?? 'active',
            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'line1' => $this->address->line1,
                'line2' => $this->address->line2,
                /*
                 * BPLO item 5's two boxes. Columns since the schema was
                 * aligned to the paper and empty on every row until the wizard
                 * stopped asking for them as one combined question — which had
                 * the officer's review guessing the split back out of `line1`
                 * with a regex, and getting it backwards on any filing whose
                 * applicant typed only the number.
                 */
                'house_bldg_no' => $this->address->house_bldg_no,
                'street' => $this->address->street,
                'city' => $this->address->city,
                'province' => $this->address->province,
                'postal_code' => $this->address->postal_code,
                // BPLO items A6 and A9. Serialised for the same reason as the
                // business fields above: the wizard has to be able to put them
                // back into the form it will save over.
                'telephone' => $this->address->telephone,
                'website' => $this->address->website,
                // BPLO items A7 and A8 — the BUSINESS's own mobile and e-mail,
                // not the account holder's. Two columns that existed and were
                // empty on every row until the wizard started asking.
                'mobile_number' => $this->address->mobile_number,
                'email' => $this->address->email,
                'latitude' => $this->address->latitude,
                'longitude' => $this->address->longitude,
                'barangay' => $this->address->relationLoaded('barangay') && $this->address->barangay ? [
                    'id' => $this->address->barangay->id,
                    'name' => $this->address->barangay->name,
                ] : null,
            ] : null),
            /*
             * BPLO items 11 / 12 — the named person, as its own object rather
             * than flattened onto the business.
             *
             * The PRIMARY row only. Item 12 prints two, the wizard writes one,
             * and the relation is plural so the second needs no migration; this
             * shape holds either way because a caller reading `owner` wants the
             * person the filing is in the name of.
             */
            'owner' => $this->whenLoaded('owners', function () {
                $primary = $this->owners->firstWhere('is_primary', true) ?? $this->owners->first();

                return $primary ? [
                    'surname' => $primary->surname,
                    'given_name' => $primary->given_name,
                    'middle_name' => $primary->middle_name,
                    'suffix' => $primary->suffix,
                    'gender' => $primary->gender,
                ] : null;
            }),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'psic_code' => $line->relationLoaded('psicCode') && $line->psicCode ? [
                    'id' => $line->psicCode->id,
                    'code' => $line->psicCode->code,
                    'title' => $line->psicCode->title,
                ] : null,
                'capitalization' => $line->capitalization,
                // What the applicant typed when their trade is not in the PSIC
                // list. Without this a reopened draft loses the free text and
                // they have to type it again.
                'line_of_business' => $line->line_of_business,
                'products_services' => $line->products_services,
            ])->values()),
        ];
    }
}
