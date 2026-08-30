<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-office application form payloads (UI prototype Parts 4-7, pages 040-044).
 * Opaque JSON keyed by permit type; no manuscript equivalent.
 *
 * Two writers share one payload: the applicant answers the questions only they
 * can answer, and the reviewing officer records the issuance dates only the
 * office can know. Anything the system already knows (application type, filing
 * date, which certificate the FSIC is for, and the floor area, storey count,
 * site tenure and authorised representative the zoning sheet carries) is
 * derived here and never asked.
 */
class OfficeFormController extends Controller
{
    /**
     * Permit types with an applicant-facing form sheet.
     *
     * The list moved onto PermitType when the clearance stage was built: it is
     * a fact about the permit type, and the stage has to answer "does Apply
     * open a form?" without going through this controller.
     */
    private const FORM_PERMIT_CODES = PermitType::OFFICE_FORM_CODES;

    /** Issuance dates: recorded by the reviewing office, never by the applicant. */
    private const OFFICER_KEYS = ['building_permit_date', 'fsec_date', 'date_issued'];

    /** GET — owner-of or application.view_all. Derived answers are merged in. */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

        $stored = ApplicationOfficeForm::with('permitType:id,code')
            ->where('application_id', $application->id)
            ->get()
            ->keyBy(fn (ApplicationOfficeForm $f) => $f->permitType?->code);

        // Every form-bearing permit type on the application shows up, even
        // before the applicant saves anything, so the wizard can render the
        // derived answers on a form the applicant has not opened yet.
        $codes = $application->permitTypes()
            ->pluck('code')
            ->filter(fn (string $code) => in_array($code, self::FORM_PERMIT_CODES, true))
            ->merge($stored->keys())
            ->unique()
            ->values();

        $forms = $codes
            ->filter(fn (string $code) => $this->readableCode($request, $code))
            ->values()
            ->map(fn (string $code) => [
                'permit_type_code' => $code,
                'form_data' => $this->withDerived($application, $code, $stored[$code]->form_data ?? []),
            ])
            ->values();

        return response()->json(['data' => $forms]);
    }

    /**
     * PUT — upserts the form payload.
     *
     * Owner: draft/returned only, and may write everything except the office
     * issuance dates. Reviewing officer: the issuance dates only, at any point
     * in the review. Derived answers are re-applied on every write, so a
     * client-supplied value for them is never trusted.
     */
    public function upsert(Request $request, Application $application, string $permitTypeCode): JsonResponse
    {
        $user = $request->user();
        $isOwner = $application->applicant_user_id === $user->id;
        /*
         * A reviewer may record issuance dates only on a filing its office is
         * part of; the permission alone is no longer enough (checklist item 56).
         *
         * Item 111 narrows it once more, to the sheet as well as the filing: the
         * issuance date on the FSIC sheet is the fire office stating when it
         * issued that certificate, so it is not the sanitary officer's to write
         * even though both offices are on the filing. readableCode() is the same
         * boundary the GET uses — a sheet you may not read is not a sheet you may
         * sign.
         */
        $isReviewer = $user->hasPermission('application.review')
            && ApplicationVisibility::canView($user, $application)
            && $this->readableCode($request, $permitTypeCode);

        abort_unless($isOwner || $isReviewer, 403, 'This application is not yours.');

        $permitType = PermitType::where('code', $permitTypeCode)->firstOrFail();

        if ($isOwner) {
            abort_unless(
                $this->ownerMayEdit($application, $permitType),
                422,
                'This form can no longer be edited. Office forms are open while the application is a draft or has been returned to you, and while a clearance you applied for is still waiting on its office.'
            );
        }

        $request->validate([
            // "present", not "required": a sheet whose every answer is derived
            // (the FSIC form) legitimately posts an empty object.
            'form_data' => ['present', 'array', 'max:512'], // guard against huge payloads
            // Birthdays can never be in the future (CEC "Birthday of Owner").
            'form_data.owner_birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            // An office cannot have issued a document on a future date.
            'form_data.building_permit_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'form_data.fsec_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'form_data.date_issued' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ], [
            'form_data.owner_birthday.before' => "The owner's birthday must be a date in the past.",
            'form_data.building_permit_date.before_or_equal' => 'The building permit date issued cannot be in the future.',
            'form_data.fsec_date.before_or_equal' => 'The FSEC date issued cannot be in the future.',
            'form_data.date_issued.before_or_equal' => 'The date issued cannot be in the future.',
        ]);

        // Opaque JSON: keep the full payload, not validated()'s narrowed keys.
        $submitted = $request->input('form_data', []);

        // Belt-and-braces size cap (~16KB serialized).
        abort_if(strlen(json_encode($submitted)) > 16384, 422, 'The form payload is too large (max 16KB).');

        abort_unless(
            $application->permitTypes()->where('permit_types.id', $permitType->id)->exists(),
            422,
            'That permit type is not part of this application.'
        );

        $existing = ApplicationOfficeForm::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id)
            ->first();
        $current = $existing?->form_data ?? [];

        if ($isOwner) {
            // The applicant owns the answers; the office dates stay as recorded.
            $formData = array_diff_key($submitted, array_flip(self::OFFICER_KEYS))
                + array_intersect_key($current, array_flip(self::OFFICER_KEYS));
        } else {
            // The officer may only touch the issuance dates.
            $formData = array_intersect_key($submitted, array_flip(self::OFFICER_KEYS)) + $current;
        }

        $formData = $this->withDerived($application, $permitType->code, $formData);

        $form = ApplicationOfficeForm::updateOrCreate(
            ['application_id' => $application->id, 'permit_type_id' => $permitType->id],
            ['form_data' => $formData]
        );
        Audit::log('office_form.saved', $form);

        return response()->json([
            'data' => [
                'permit_type_code' => $permitType->code,
                'form_data' => $form->form_data,
            ],
        ]);
    }

    /**
     * When the applicant may still write a sheet. TWO windows, not one.
     *
     * The first is the filing being theirs: a draft they have not sent, or a
     * filing an office has returned to them.
     *
     * The second is the clearance stage, and it is the one that is easy to
     * delete by accident. Clearances are applied for AFTER the first payment
     * (docs/clearances-after-payment.md), so the filing is already under review
     * — or for inspection, or approved — at the moment its office sheet first
     * becomes reachable. Without this window every clearance form would open
     * read-only the instant it appeared, and the applicant would be billed for
     * a clearance whose form they could never fill in.
     *
     * It closes when THAT office signs off, and not when the filing moves.
     * `completed` on the clearance's own assignment is the office saying it has
     * read the sheet and accepted it; letting the applicant rewrite it
     * afterwards would leave the register holding answers no officer approved,
     * under an approval that names them. Every other assignment state leaves it
     * open, `returned` deliberately included — an office asking for a
     * correction is the clearest possible case for the form being editable.
     *
     * Per SHEET rather than per filing, because the six clearances move
     * independently: City Health completing its review must not freeze the
     * market sheet the applicant applied for an hour ago.
     */
    private function ownerMayEdit(Application $application, PermitType $permitType): bool
    {
        if (in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true)) {
            return true;
        }

        // A closed filing takes nothing more, whatever its assignments say.
        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true)) {
            return false;
        }

        $assignment = $application->assignments()
            ->where('department_id', $permitType->issuing_department_id)
            ->first();

        return $assignment !== null && $assignment->status !== AssignmentStatus::Completed;
    }

    /**
     * Overlay the answers the system already holds on top of a payload. These
     * always win: the paper form still carries them, but nobody types them.
     */
    private function withDerived(Application $application, ?string $permitTypeCode, array $formData): array
    {
        if ($permitTypeCode === null || ! in_array($permitTypeCode, self::FORM_PERMIT_CODES, true)) {
            return $formData;
        }

        // Renewals and amendments both act on a business that already operates;
        // only a genuinely new application is "new" to the inspecting office.
        $existingBusiness = $application->application_type !== ApplicationType::New;

        // The filing date: submitted_at once the application is filed, today
        // while it is still being filled in. Never typed by anyone.
        $derived = [
            'application_date' => ($application->submitted_at ?? now())->toDateString(),
        ];

        if ($permitTypeCode === 'ZONING') {
            // VII. Nature of Application — New Business or Renewal.
            $derived['application_type'] = $existingBusiness
                ? 'Renewal of Locational Clearance'
                : 'New Locational Clearance';

            /*
             * VIII.A. Floor Area to be/being Utilized. The zoning processing
             * fee is charged per square metre of total floor area, so CPDD
             * cannot assess the clearance without this number — and the
             * applicant already gave it on the Business & Tax Profile, where it
             * is required of every filing carrying the business permit itself.
             * Asking again on this sheet would be asking the same question
             * twice and inviting two answers.
             *
             * Left absent rather than zeroed when it is genuinely missing: a
             * blank box the applicant can be sent back to fill is honest, and
             * "0 sq. m." on a locational clearance is not.
             */
            $floorArea = $application->fee_profile['floor_area_sqm'] ?? null;
            if (is_numeric($floorArea)) {
                $derived['total_floor_area_sqm'] = (string) (0 + $floorArea);
            }

            // VIII.B. No. of Storey of Building — the same answer from the same
            // profile, cast to an int because a building has whole storeys and
            // "2.0" on a planning form invites a question nobody meant to ask.
            $storeys = $application->fee_profile['storeys'] ?? null;
            if (is_numeric($storeys)) {
                $derived['building_storeys'] = (string) (int) $storeys;
            }

            /*
             * VIII.C and VIII.D. Name and address of the lessor, which the form
             * asks only of a lessee. Location & Zoning has already answered
             * whether the premises are rented and from whom, and the clearance
             * already requires the matching Lease Contract or Land Title, so
             * one line stands in for both boxes and makes the sheet match the
             * document attached to it.
             *
             * `business` is nullable (soft-deleted), so the tenure question
             * genuinely has no answer on such a filing and the field stays
             * blank rather than claiming the site is owned.
             */
            $business = $application->business;
            if ($business !== null) {
                $lessor = trim((string) $business->lessor_name);
                $derived['site_tenure'] = match (true) {
                    ! $business->is_rented => 'Owned or occupied by the applicant',
                    $lessor !== '' => 'Leased from '.$lessor,
                    default => 'Leased',
                };
            }

            /*
             * IX. Authorized Representative. One answer about the applicant,
             * not about an office, and the BFP sheet has always asked it — so
             * when that sheet is part of the filing it keeps the question and
             * this one carries the answer read-only. When it is not, nobody has
             * asked, and the zoning sheet takes the input itself.
             *
             * The marker is what tells the sheet which of those it is; the
             * value is derived even when blank, so clearing the name on the BFP
             * sheet clears it here too. That does mean applying for FSIC after
             * typing a name here replaces it with the (empty) BFP answer. Two
             * visible fields that disagree would be worse than losing an
             * optional name at the moment a second sheet takes the question
             * over.
             */
            if ($application->permitTypes()->where('code', 'FSIC')->exists()) {
                $derived['authorized_representative_source'] = 'FSIC';
                $derived['authorized_representative'] = $this->fsicRepresentative($application);
            }
        }
        if ($permitTypeCode === 'SANITARY') {
            $derived['application_type'] = $existingBusiness ? 'Renewal' : 'New';

            /*
             * "No. of Workers Requiring Health Certificates" — the same
             * question the Business & Tax Profile already asks, and the same
             * one the applicant is BILLED on.
             *
             * `sanitary.health_certificate` (Sec. 4D.02) charges ₱50 per
             * employee per year, `basis: employees`, gated on the
             * `employees_need_health_certificates` flag — so the number the
             * City Health Office actually assesses is `fee_profile.employees`,
             * declared on the profile. This sheet then asked for it a second
             * time, in a free-text box nothing reads.
             *
             * That is the capitalization case again, and it is worse here:
             * capitalization's two answers merely drifted, whereas these two
             * appear on the same filing as a number on the CHO's own sheet and
             * a different number on the Tax Order of Payment for the same fee.
             * An officer reading "4 workers" beside a bill for five is looking
             * at a discrepancy the applicant never made.
             *
             * So it is derived, exactly as the zoning sheet's floor area is.
             * Where the flag is not set, no employee needs a certificate and
             * the fee is not charged — "None" is the honest answer, not blank.
             *
             * ASSUMPTION (docs/questions-for-malabon.md §E — the CHO paper form
             * has been requested and not received): the paper's box means every
             * employee who must hold a certificate, which is what the ordinance
             * bills. If CHO confirms it means some narrower subset — office
             * staff excluded from a food establishment's count, say — then it
             * is a genuinely separate question and should be asked again here,
             * AND the fee basis is wrong, because the fee would be billing the
             * wrong headcount today.
             */
            $flags = $application->fee_profile['flags'] ?? [];
            $needsCertificates = is_array($flags)
                && in_array('employees_need_health_certificates', $flags, true);
            $employees = $application->fee_profile['employees'] ?? null;

            $derived['workers_requiring_health_certs'] = match (true) {
                ! $needsCertificates => 'None',
                is_numeric($employees) => (string) (int) $employees,
                // Flagged but no headcount: the profile is half-filled, so say
                // nothing rather than print a zero the office would act on.
                default => '',
            };
        }
        if ($permitTypeCode === 'CEC') {
            $derived['application_type'] = $existingBusiness ? 'Renewal of CEC' : 'Initial Application';
        }
        if ($permitTypeCode === 'FSIC') {
            $forOccupancy = $application->permitTypes()->where('code', 'OCCUPANCY')->exists();
            $derived['certificate_applied_for'] = match (true) {
                $forOccupancy => 'FSIC for Certificate of Occupancy',
                $existingBusiness => 'FSIC for Business Permit (Renewal of Business)',
                default => 'FSIC for Business Permit (New Business)',
            };
        }
        if ($permitTypeCode === 'MARKET') {
            /*
             * The one derived answer on a sheet that is otherwise invented
             * (checklist item 109 — the office has no paper form). Worth
             * deriving precisely because it is invented: whether the stall
             * holder is new to the market or renewing is the single question
             * the office would certainly ask, and it is the single question the
             * filing can already answer for itself.
             */
            $derived['application_type'] = $existingBusiness
                ? 'Renewal of Market Clearance'
                : 'New Market Clearance';
        }
        // OCCUPANCY's own "application_type" is Full vs Partial occupancy — a
        // real applicant decision, not the new/renewal the system already knows.

        return $derived + $formData;
    }

    /**
     * The authorised representative as answered on the BFP sheet, or ''.
     *
     * Its own query rather than a preloaded relation because withDerived() runs
     * for one sheet at a time and is reached from both index() and upsert(); a
     * sheet asking for another sheet's answer is the exception, not the rule,
     * and it only happens for ZONING.
     */
    private function fsicRepresentative(Application $application): string
    {
        $form = ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($query) => $query->where('code', 'FSIC'))
            ->first();

        return trim((string) ($form?->form_data['authorized_representative'] ?? ''));
    }

    /**
     * May this reader see this particular office's sheet? (checklist item 111)
     *
     * The RULE is `ApplicationVisibility::readsOfficeSheet()` and its reasoning
     * is written there. What stays here is the translation from a permit-type
     * CODE — which is what this controller's routes are keyed on — to the
     * issuing department the rule compares against.
     *
     * It used to be both, and that is why the fix leaked: the officer review
     * sheet loads its office forms from `GET /assignments/{id}`, which cannot
     * call a private method on this controller and therefore filtered nothing
     * (SEP-1). Keep the rule shared. If this ever grows a second clause, the
     * clause belongs in ApplicationVisibility, or the two endpoints will
     * disagree again.
     */
    private function readableCode(Request $request, string $code): bool
    {
        return ApplicationVisibility::readsOfficeSheet(
            $request->user(),
            $this->issuingDepartmentId($code),
        );
    }

    /**
     * Which office issues this clearance. Memoised per request because index()
     * asks once per code and upsert() once per call, and the answer is seeded
     * reference data that cannot change inside one request.
     *
     * @var array<string, int|null>
     */
    private array $issuingDepartments = [];

    private function issuingDepartmentId(string $code): ?int
    {
        if (! array_key_exists($code, $this->issuingDepartments)) {
            $this->issuingDepartments[$code] = PermitType::where('code', $code)
                ->value('issuing_department_id');
        }

        return $this->issuingDepartments[$code];
    }

    /** Owner, an office routed this filing, or BPLO/admin (checklist item 56). */
    private function authorizeView(Request $request, Application $application): void
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You may not view this application.'
        );
    }
}
