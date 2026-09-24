<?php

namespace App\Http\Resources;

use App\Models\PermitType;
use App\Support\OfficeFormAnswers;
use App\Support\PermitFace;
use Illuminate\Http\Request;

/**
 * One permit, as the administrator's register table reads it.
 *
 * ── Why this is a second resource and not more keys on PermitResource ──────
 *
 * `PermitResource` is a DOCUMENTED contract — docs/api-contract.md names its
 * eleven keys — and it is what the owner's Profile, the filing detail screen
 * and `/applications/{id}` all render. The register table needs roughly three
 * times that, including the office sheet the applicant filled in, and hanging
 * all of it on the shared resource would:
 *
 *  - put the office answers on every owner-facing permit payload, which is
 *    more of the filing than those screens ask for; and
 *  - make a heavier query the price of reading a single certificate.
 *
 * So the extra lives here, the base stays exactly as contracted, and
 * `parent::toArray()` guarantees the two cannot describe the same permit
 * differently.
 *
 * ── What "all the information about the permit" means on this screen ───────
 *
 * The client asked for every detail an office needs, per office, in one wide
 * table. That is four groups, and they come from four different places:
 *
 *  1. The IDENTIFIERS — BAN first, then the permit number and the tracking ID.
 *     BAN is the business account number (`businesses.ban`, `BP-YYYY-NNNN`),
 *     the number the City files a business under across every permit it ever
 *     holds. It leads because it is the only one of the three that is stable:
 *     a permit number names one certificate and a tracking ID names one
 *     filing, while the BAN names the business behind all of them.
 *
 *  2. The FACE — what is printed on the certificate itself. Read through
 *     `PermitFace::forPrinting`, never off the live business, because a permit
 *     is a snapshot of what was true when it was signed; see that class and
 *     the `issued_details` migration. A table that showed today's address
 *     beside a certificate issued under the old one would be quietly wrong
 *     about a legal document.
 *
 *  3. The RECORD — status, the validity pair, who signed it and when, the
 *     permit it succeeded, and the revocation columns. Dates go out as ISO
 *     strings and are formatted by the browser.
 *
 *  4. The OFFICE SHEET — the form the applicant filled in for THIS permit's
 *     office, which is the part the client specifically asked for ("mga
 *     finill-outan kada offices"). Five of the six permit types have one
 *     (`PermitType::OFFICE_FORM_CODES`); the Mayor's Permit does not, and
 *     answers `null` rather than an empty object so the table can tell "this
 *     office asks nothing" from "this office asked and was not answered".
 *
 * ── The office sheet goes through OfficeFormAnswers::derive ────────────────
 *
 * Not read raw off `application_office_forms.form_data`. That class exists
 * because the register does not store the answers it can work out for itself —
 * the filing date, the nature of the application, the certificate applied for,
 * the floor area already on the Business & Tax Profile — and a screen reading
 * the raw column sees those boxes blank on a sheet that prints them filled.
 *
 * Its own header records the cost of getting this wrong: a CENRO session once
 * opened a filing it had been routed and saw the BPLO form and nothing of its
 * own, because one of the two doors mapped saved rows only. This is a third
 * door onto the same answers, so it uses the same derivation rather than
 * becoming the next thing to drift.
 */
class PermitRegisterResource extends PermitResource
{
    public function toArray(Request $request): array
    {
        $face = PermitFace::forPrinting($this->resource);

        return array_merge([
            /*
             * The business account number, and it is FIRST — before the id,
             * before the permit number — because the client asked for the
             * table to lead with it and a resource's key order is the column
             * order the table renders.
             *
             * Read off the live business rather than the snapshot: `PermitFace`
             * does not capture it, and unlike the address it does not change —
             * a BAN is issued once and kept. Null on a permit whose business
             * has been removed from the register, which the table prints as a
             * dash rather than a blank.
             */
            'ban' => $this->whenLoaded('business', fn () => $this->business?->ban),
        ], parent::toArray($request), [
            // The certificate face. Keys match PermitFace::KEYS exactly.
            'face' => $face,

            'valid_from' => optional($this->valid_from)->toDateString(),
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'issued_by' => $this->whenLoaded(
                'issuedBy',
                fn () => $this->issuedBy?->fullName(),
            ),

            /*
             * The permit this one replaced, by NUMBER rather than id: the
             * number is what a reader can look up, and an id in a table cell
             * is a dead end. Null on an original issuance, which is most of
             * them.
             */
            'prior_permit_number' => $this->whenLoaded(
                'priorPermit',
                fn () => $this->priorPermit?->permit_number,
            ),

            /*
             * Revocation, carried even though the live register holds no
             * revoked row. The column exists, nothing writes it yet (see the
             * note on the Permits page about why Revoke is not built), and a
             * table that omitted the reason would have to be changed again on
             * the day one appears — by which time a revoked permit would be
             * rendering as an ordinary one.
             */
            'revoked_at' => optional($this->revoked_at)->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,

            /*
             * The office's own sheet for this permit, or null where that
             * office does not ask for one.
             */
            'office_form' => $this->officeForm(),
        ]);
    }

    /**
     * The answers on this permit's office sheet — saved and derived together.
     *
     * Returns null, not [], for a permit type with no sheet at all. The two
     * are different facts and the table says each differently: "this office
     * asks nothing" against "this office asked and nobody answered".
     *
     * @return array<string, mixed>|null
     */
    private function officeForm(): ?array
    {
        $code = $this->relationLoaded('permitType') ? $this->permitType?->code : null;

        if ($code === null || ! in_array($code, PermitType::OFFICE_FORM_CODES, true)) {
            return null;
        }

        if (! $this->relationLoaded('application') || $this->application === null) {
            return null;
        }

        $application = $this->application;

        /*
         * The saved row for THIS permit type, if the applicant opened the
         * sheet. `firstWhere` over an already-loaded collection rather than a
         * query: the controller eager-loads `application.officeForms`, and a
         * query here would be one round trip per row on a 25-row page.
         */
        $saved = $application->relationLoaded('officeForms')
            ? $application->officeForms->firstWhere('permit_type_id', $this->permit_type_id)
            : null;

        return OfficeFormAnswers::derive(
            $application,
            $code,
            is_array($saved?->form_data) ? $saved->form_data : [],
        );
    }
}
