<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
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
 * date, which certificate the FSIC is for) is derived here and never asked.
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

        $forms = $codes->map(fn (string $code) => [
            'permit_type_code' => $code,
            'form_data' => $this->withDerived($application, $code, $stored[$code]->form_data ?? []),
        ]);

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
        // A reviewer may record issuance dates only on a filing its office is
        // part of; the permission alone is no longer enough (checklist item 56).
        $isReviewer = $user->hasPermission('application.review')
            && ApplicationVisibility::canView($user, $application);

        abort_unless($isOwner || $isReviewer, 403, 'This application is not yours.');

        $permitType = PermitType::where('code', $permitTypeCode)->firstOrFail();

        if ($isOwner) {
            abort_unless(
                $this->ownerMayEdit($application),
                422,
                'Office forms can only be edited while the application is a draft or has been returned to you.'
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
     * When the applicant may still write a sheet: while the filing is theirs.
     *
     * There was briefly a second window here, for a clearance applied for
     * after payment — the stage opened when the filing was already under
     * review, so without it every office sheet would have opened read-only the
     * moment it became reachable. The clearances are chosen in the wizard now
     * (docs/clearances-before-payment.md), which is a draft by definition, so
     * that window collapsed back into this one.
     */
    private function ownerMayEdit(Application $application): bool
    {
        return in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true);
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

        if ($permitTypeCode === 'SANITARY') {
            $derived['application_type'] = $existingBusiness ? 'Renewal' : 'New';
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
        // OCCUPANCY's own "application_type" is Full vs Partial occupancy — a
        // real applicant decision, not the new/renewal the system already knows.

        return $derived + $formData;
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
