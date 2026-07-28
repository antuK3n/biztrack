<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-office application form payloads (UI prototype Parts 4-7, pages 040-044).
 * Opaque JSON keyed by permit type; no manuscript equivalent.
 */
class OfficeFormController extends Controller
{
    /** GET — owner-of or application.view_all. */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

        $forms = ApplicationOfficeForm::with('permitType:id,code')
            ->where('application_id', $application->id)
            ->get()
            ->map(fn (ApplicationOfficeForm $f) => [
                'permit_type_code' => $f->permitType?->code,
                'form_data' => $f->form_data,
            ])
            ->values();

        return response()->json(['data' => $forms]);
    }

    /** PUT — owner only, draft/returned only. Upserts the form payload. */
    public function upsert(Request $request, Application $application, string $permitTypeCode): JsonResponse
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
        abort_unless(
            in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true),
            422,
            'Office forms can only be edited while the application is a draft or returned.'
        );

        $request->validate([
            'form_data' => ['required', 'array', 'max:512'], // guard against huge payloads
            // Birthdays can never be in the future (CEC "Birthday of Owner").
            'form_data.owner_birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
        ], [
            'form_data.owner_birthday.before' => "The owner's birthday must be a date in the past.",
        ]);

        // Opaque JSON: keep the full payload, not validated()'s narrowed keys.
        $formData = $request->input('form_data', []);

        // Belt-and-braces size cap (~16KB serialized).
        abort_if(strlen(json_encode($formData)) > 16384, 422, 'The form payload is too large (max 16KB).');

        /*
         * Applicant-side forms never set dates: the application date comes
         * from submitted_at, and "Date Issued" values are recorded by the
         * issuing office at issuance. Strip them if a client sends them.
         */
        unset(
            $formData['application_date'],
            $formData['date_issued'],
            $formData['building_permit_date'],
            $formData['fsec_date'],
        );

        $permitType = PermitType::where('code', $permitTypeCode)->firstOrFail();
        abort_unless(
            $application->permitTypes()->where('permit_types.id', $permitType->id)->exists(),
            422,
            'That permit type is not part of this application.'
        );

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

    private function authorizeView(Request $request, Application $application): void
    {
        if ($application->applicant_user_id === $request->user()->id) {
            return;
        }
        abort_unless(
            $request->user()->hasPermission('application.view_all'),
            403,
            'You may not view this application.'
        );
    }
}
